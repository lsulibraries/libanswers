<?php

declare(strict_types=1);

namespace Drupal\libanswers\Form;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\libanswers\LibAnswersClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A question for a LibAnswers queue, sent through the API as a ticket.
 *
 * Build it with the queue and, optionally, the fragment to return to:
 *
 * @code
 * \Drupal::formBuilder()->getForm(AskForm::class, 2616, 'ask-special-collections');
 * @endcode
 *
 * The fields are the queue's AskUs form's (same names, same options), so
 * tickets arrive as they would from AskUs. Spam: Honeypot's hidden field
 * only, not its time limit, which would stop the page from being cached.
 *
 * Two ways in:
 * - A script posting in the background sends the header
 *   X-LibAnswers-Fetch: 1 and gets JSON back: 200 { ok: true } once the
 *   ticket exists, 422 { errors: { field: message } } when a field (or
 *   Honeypot) fails, 502 { ok: false } when LibAnswers refuses it.
 * - Without a script, the form posts as usual and returns to the page
 *   (at the fragment) with a message.
 */
final class AskForm extends FormBase {

  /**
   * Each queue's own fields: name => [label, options]. Its AskUs widget's.
   */
  private const QUEUE_FIELDS = [
    // Special Collections (widget 19447).
    2616 => [
      'val1' => ['Affiliation', [
        1 => 'LSU Undergrad',
        2 => 'LSU Grad Student',
        3 => 'LSU Faculty/Staff',
        4 => 'LSU Alumni',
        5 => 'Other College/University',
        6 => 'General Public',
        7 => 'Independent Researcher',
      ]],
      'val2' => ['Purpose of research', [
        1 => 'Class Assignment',
        2 => 'Publication/Broadcast',
        3 => 'Work/Business',
        4 => 'Genealogy',
        5 => 'Scholarly Research',
        6 => 'Thesis / Dissertation',
        7 => 'Personal',
        8 => 'Other',
      ]],
    ],
  ];

  public function __construct(private readonly LibAnswersClient $client) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('libanswers.client'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'libanswers_ask_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $queue = 2616, string $fragment = ''): array {
    $form_state->set('libanswers_queue', $queue);
    $form_state->set('libanswers_fragment', $fragment);
    $form['#theme'] = 'libanswers_ask_form';
    // The page's own script checks the fields and says what's wrong.
    $form['#attributes']['novalidate'] = 'novalidate';

    foreach (self::QUEUE_FIELDS[$queue] ?? [] as $name => [$label, $options]) {
      $form[$name] = [
        '#type' => 'select',
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        '#title' => $this->t($label),
        '#options' => array_map(fn (string $option) => $this->t($option), $options),
        '#empty_option' => $this->t('Select one'),
        '#empty_value' => '',
        '#required' => TRUE,
      ];
    }
    $form['pname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#maxlength' => 100,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'name'],
    ];
    $form['pemail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#maxlength' => 100,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'email'],
    ];
    $form['pquestion'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Question'),
      '#maxlength' => 250,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['pdetails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('More detail'),
      '#rows' => 5,
      '#resizable' => 'vertical',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send question'),
    ];

    // Honeypot's hidden field; its validation runs with the elements'.
    $honeypot = \Drupal::hasService('honeypot') ? \Drupal::service('honeypot') : NULL;
    if ($honeypot && method_exists($honeypot, 'addFormProtection')) {
      $honeypot->addFormProtection($form, $form_state, ['honeypot']);
    }
    elseif (function_exists('honeypot_add_form_protection')) {
      honeypot_add_form_protection($form, $form_state, ['honeypot']);
    }

    // After every other check, answer a background post's errors as JSON.
    $form['#validate'] = ['::validateForm', '::answerErrors'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Required fields, the email, lengths and the menus' options are
    // checked by Form API; nothing else to add.
  }

  /**
   * A background post with errors: JSON, not the page with messages.
   */
  public function answerErrors(array &$form, FormStateInterface $form_state): void {
    if (!$this->isBackground() || !$form_state->hasAnyErrors()) {
      return;
    }
    $errors = [];
    foreach ($form_state->getErrors() as $name => $message) {
      $errors[$name] = trim(strip_tags((string) $message));
    }
    // The messages were for the page, which won't be shown.
    $this->messenger()->deleteAll();
    throw new EnforcedResponseException(new JsonResponse(['errors' => $errors], 422));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $queue = (int) $form_state->get('libanswers_queue');
    $fields = ['quid' => $queue];
    foreach (array_keys(self::QUEUE_FIELDS[$queue] ?? []) as $name) {
      $fields[$name] = (string) $form_state->getValue($name);
    }
    foreach (['pquestion', 'pdetails', 'pname', 'pemail'] as $name) {
      $fields[$name] = trim((string) $form_state->getValue($name));
    }

    try {
      $this->client->createTicket($fields);
      $sent = TRUE;
    }
    catch (\RuntimeException $e) {
      // LibAnswersClient has logged why.
      $sent = FALSE;
    }

    if ($this->isBackground()) {
      $form_state->setResponse(new JsonResponse(['ok' => $sent], $sent ? 200 : 502));
      return;
    }
    if ($sent) {
      $this->messenger()->addStatus($this->t('Thank you. Your question has been sent; we’ll reply to @email.', ['@email' => $fields['pemail']]));
    }
    else {
      $this->messenger()->addError($this->t('Sorry, your question couldn’t be sent. Please try again, or ask on <a href=":url">AskUs</a>.', [':url' => 'https://askus.lib.lsu.edu/']));
    }
    $fragment = (string) $form_state->get('libanswers_fragment');
    $form_state->setRedirectUrl(Url::fromRoute('<current>', [], $fragment !== '' ? ['fragment' => $fragment] : []));
  }

  /**
   * Whether the page's script sent this, wanting JSON.
   */
  private function isBackground(): bool {
    return $this->getRequest()->headers->get('X-LibAnswers-Fetch') === '1';
  }

}
