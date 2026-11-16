<?php

declare(strict_types=1);

namespace Drupal\libanswers\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\libanswers\Form\AskForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A queue's question form as an HTML fragment, without a page.
 *
 * For a script that places the form in a drawer on any page, so the page
 * itself carries no form until the drawer opens. The form's action is this
 * route, so its posts come back here and AskForm answers them as it does on
 * a page (JSON for a background post). The theme's form templates and
 * alters apply as usual. Cached like a page: Honeypot adds its hidden
 * field only, not its time limit (AskForm).
 *
 * No libraries are sent with the fragment: the page that fetches it brings
 * the form's script and styles.
 */
final class AskFormController extends ControllerBase {

  public function __construct(private readonly RendererInterface $renderer) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('renderer'));
  }

  /**
   * The form for one queue; 404 for a queue AskForm has no fields for.
   */
  public function form(string $queue): CacheableResponse {
    $queue = (int) $queue;
    if (!in_array($queue, AskForm::queues(), TRUE)) {
      throw new NotFoundHttpException();
    }
    $build = $this->formBuilder()->getForm(AskForm::class, $queue);
    $html = (string) $this->renderer->renderRoot($build);

    $response = new CacheableResponse($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    $response->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));
    // Only as a part of a page, never on its own in a search engine.
    $response->headers->set('X-Robots-Tag', 'noindex');
    return $response;
  }

}
