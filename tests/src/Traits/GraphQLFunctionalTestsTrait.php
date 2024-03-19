<?php

namespace Drupal\Tests\graphql\Traits;

use GuzzleHttp\RequestOptions;

/**
 * Helper trait for GraphQL functional tests.
 */
trait GraphQLFunctionalTestsTrait {

  /**
   * Send an APQ request.
   *
   * @param string $endpoint
   *   The server endpoint.
   * @param string $query
   *   The GraphQl query to execute.
   * @param string $variables
   *   The variables for the query.
   * @param bool $withQuery
   *   Whether to request with query parameter.
   *
   * @return array
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function getRequestOptions(string $endpoint, string $query, string $variables = '', bool $withQuery = FALSE) {
    $hash = hash('sha256', $query);
    $extensions = '{"persistedQuery":{"version":1,"sha256Hash":"' . $hash . '"}}';

    $requestOptions = [];
    $requestOptions[RequestOptions::QUERY]['extensions'] = $extensions;

    if ($variables !== '') {
      $requestOptions[RequestOptions::QUERY]['variables'] = $variables;
    }
    if ($withQuery) {
      $requestOptions[RequestOptions::QUERY]['query'] = $query;
    }
    return $requestOptions;
  }

}
