<?php

declare(strict_types=1);

namespace Drupal\libanswers;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The LibAnswers API (v1.1): tickets and queue fields.
 *
 * Credentials come from settings.php only, never config, so they stay out
 * of the database, config exports and the repository:
 *
 * @code
 * $settings['libanswers'] = [
 *   'client_id' => '…',
 *   'client_secret' => '…',
 *   // Optional; this is the default.
 *   'base_url' => 'https://askus.lib.lsu.edu',
 * ];
 * @endcode
 *
 * Each call gets a fresh token (client credentials grant): questions are
 * rare, so one extra request each is cheaper than storing a bearer token.
 */
final class LibAnswersClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether settings.php has a client ID and secret.
   */
  public function isConfigured(): bool {
    $settings = Settings::get('libanswers', []);
    return !empty($settings['client_id']) && !empty($settings['client_secret']);
  }

  /**
   * Creates a ticket.
   *
   * @param array $fields
   *   The ticket's fields, as the API names them: quid (the queue),
   *   pquestion, pdetails, pname, pemail, and the queue's own fields
   *   (val1, val2, …; see queueFields()).
   *
   * @return array
   *   The API's answer, decoded.
   *
   * @throws \RuntimeException
   *   When the module isn't configured or the API refuses the ticket.
   */
  public function createTicket(array $fields): array {
    return $this->request('POST', 'ticket/create', ['form_params' => $fields]);
  }

  /**
   * A queue's form fields, to check what createTicket() should send.
   *
   * @code
   * drush php:eval 'print_r(\Drupal::service("libanswers.client")->queueFields(2616));'
   * @endcode
   */
  public function queueFields(int $queue): array {
    return $this->request('GET', "queue/$queue/formfields");
  }

  /**
   * One authorised API call; the answer as an array.
   */
  private function request(string $method, string $path, array $options = []): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('LibAnswers is not configured: add $settings[\'libanswers\'] to settings.php.');
    }
    $options['headers']['Authorization'] = 'Bearer ' . $this->token();
    $options['headers']['Accept'] = 'application/json';
    $options['timeout'] = 15;
    try {
      $response = $this->httpClient->request($method, $this->baseUrl() . '/api/1.1/' . $path, $options);
    }
    catch (\Throwable $e) {
      $this->logger->error('LibAnswers @method @path failed: @message', [
        '@method' => $method,
        '@path' => $path,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('The LibAnswers API refused the request.', 0, $e);
    }
    return json_decode((string) $response->getBody(), TRUE) ?: [];
  }

  /**
   * A bearer token for this request.
   */
  private function token(): string {
    $settings = Settings::get('libanswers', []);
    try {
      $response = $this->httpClient->request('POST', $this->baseUrl() . '/api/1.1/oauth/token', [
        'form_params' => [
          'grant_type' => 'client_credentials',
          'client_id' => $settings['client_id'],
          'client_secret' => $settings['client_secret'],
        ],
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 15,
      ]);
    }
    catch (\Throwable $e) {
      // Never log the request: it carries the secret.
      $this->logger->error('LibAnswers sign-in failed (HTTP @code). Check the client ID and secret in settings.php.', [
        '@code' => method_exists($e, 'getCode') ? $e->getCode() : 0,
      ]);
      throw new \RuntimeException('LibAnswers sign-in failed.', 0, $e);
    }
    $token = json_decode((string) $response->getBody(), TRUE)['access_token'] ?? '';
    if ($token === '') {
      throw new \RuntimeException('LibAnswers sign-in returned no token.');
    }
    return $token;
  }

  /**
   * The LibAnswers site, without a trailing slash.
   */
  private function baseUrl(): string {
    return rtrim(Settings::get('libanswers', [])['base_url'] ?? 'https://askus.lib.lsu.edu', '/');
  }

}
