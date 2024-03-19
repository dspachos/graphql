<?php

namespace Drupal\Tests\graphql\Functional\Framework;

use Drupal\Component\Serialization\Json;
use Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber;
use Drupal\graphql\Entity\Server;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\graphql\Functional\GraphQLFunctionalTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the automatic persisted query plugin with page cache.
 *
 * @group graphql
 */
class PersistedQueryDynamicPageCacheTest extends GraphQLFunctionalTestBase {

  /**
   * The GraphQL server.
   *
   * @var \Drupal\graphql\Entity\Server
   */
  protected $server;

  /**
   * The client for the request.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;



  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'page_cache',
    'dynamic_page_cache',
    'graphql_persisted_queries_cache_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    // Create some test articles.
    Node::create([
      'nid' => 1,
      'type' => 'article',
      'title' => 'Test Article 1',
    ])->save();

    Node::create([
      'nid' => 2,
      'type' => 'page',
      'title' => 'Test Page 1',
    ])->save();

    $config = [
      'schema' => 'example',
      'name' => 'example',
      'endpoint' => '/graphql',
      'persisted_queries_settings' => [
        'automatic_persisted_query' => [
          'weight' => 0,
        ],
      ],
    ];

    $this->server = Server::create($config);
    $this->server->save();
    \Drupal::service('router.builder')->rebuild();

    $anonymousRole = Role::load(RoleInterface::ANONYMOUS_ID);
    $this->grantPermissions($anonymousRole, [
      'execute ' . $this->server->id() . ' persisted graphql requests',
      'execute ' . $this->server->id() . ' arbitrary graphql requests',
    ]
    );
    /** @var \GuzzleHttp\Client $client */
    $this->client = $this->container->get('http_client_factory')->fromOptions([
      'timeout' => NULL,
      'verify' => FALSE,
    ]);

  }

  /**
   * Test with dynamic page cache.
   *
   * Tests that cache context for two different persistent queries
   * with the exact same variables and structure.
   */
  public function testDynamicPageCache(): void {

    // The two queries have the exact same structure,
    // only difference is the queryId.
    $query = <<<GQL
      query (\$id: Int!) {
        article(id: \$id) {
          id
          title
        }
      }
    GQL;

    $variables = '{"id": 1}';
    // This will X-Drupal-Dynamic-Cache' => 'MISS' (1).
    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables, TRUE);
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['MISS']);

    // This will X-Drupal-Dynamic-Cache' => 'HIT'.
    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables);
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['HIT']);
    $data = Json::decode((string) $response->getBody());
    $this->assertEquals('TEST ARTICLE 1', $data['data']['article']['title']);

    // Normally this should be X-Drupal-Dynamic-Cache' => 'MISS'
    // Without the fix this will X-Drupal-Dynamic-Cache' => 'HIT' as well.
    $query = <<<GQL
      query (\$id: Int!) {
        page(id: \$id) {
          id
          title
        }
      }
    GQL;
    $variables = '{"id": 2}';
    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables, TRUE);
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['MISS']);
    $data = Json::decode((string) $response->getBody());
    $this->assertEquals('TEST PAGE 1', $data['data']['page']['title']);

    // This will X-Drupal-Dynamic-Cache' => 'HIT' from the previous one.
    // Without the fix, this will be HIT but for the first one (1).
    $query = <<<GQL
      query {
        articles {
          total
          items {
            id
            title
          }
        }
      }
    GQL;
    $variables = '';

    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables, TRUE);
    // This will X-Drupal-Dynamic-Cache' => 'MISS'.
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['MISS']);
    // This will X-Drupal-Dynamic-Cache' => 'HIT' as well.
    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables);
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['HIT']);
    $data = Json::decode((string) $response->getBody());
    $this->assertEquals('TEST ARTICLE 1', $data['data']['articles']['items']['0']['title']);

    // Trying the same query, different queryID, same variables etc.
    $query = <<<GQL
      query {
        pages {
          total
          items {
            id
            title
          }
        }
      }
    GQL;
    $variables = '';

    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables, TRUE);
    // Without the fix this will X-Drupal-Dynamic-Cache' => 'HIT' as well.
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['MISS']);
    // This will X-Drupal-Dynamic-Cache' => 'HIT' as well.
    $options = $this->getRequestOptions($this->server->endpoint, $query, $variables);
    $response = $this->client->request('GET', $this->getAbsoluteUrl($this->server->endpoint), $options);
    $this->assertEquals($response->getStatusCode(), 200);
    $this->assertEquals($response->getHeader(DynamicPageCacheSubscriber::HEADER), ['HIT']);
    $data = Json::decode((string) $response->getBody());
    $this->assertEquals('TEST PAGE 1', $data['data']['pages']['items']['0']['title']);

  }

}
