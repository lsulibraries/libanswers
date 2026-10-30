# LibAnswers

Sends questions from a Drupal form to a LibAnswers queue as tickets, through
the LibAnswers API (v1.1, `POST /ticket/create`), so staff answer them in
LibAnswers as they do questions sent from AskUs. Honeypot guards the form.

Used by the Palefire theme's Ask Special Collections section on
`/v2/special` (queue 2616, Special Collections).

## Why not post to the AskUs widget's endpoint

The page used to post straight to `askus.lib.lsu.edu/1.0/form/submit`, as the
LibAnswers widget does. LibAnswers now answers that with 449 unless the post
carries its ALTCHA proof of work, an undocumented protocol. The API is
documented and needs no bot check, so spam protection moves to Drupal
(Honeypot).

## Setup

1. In LibAnswers: **Admin → API → API Authentication**, create an
   application (client credentials) allowed to create tickets.
2. On each server, in `web/sites/default/settings.php` (never in config or a
   repository):

   ```php
   $settings['libanswers'] = [
     'client_id' => '…',
     'client_secret' => '…',
   ];
   ```

3. Install and enable (libstage first):

   ```
   cx require lsulibraries/libanswers
   drush en libanswers
   drush cr
   ```

4. Check the credentials and the queue's fields (no ticket is created):

   ```
   drush php:eval 'print_r(\Drupal::service("libanswers.client")->queueFields(2616));'
   ```

   The form sends `quid`, `pquestion`, `pdetails`, `pname`, `pemail`,
   `val1` (Affiliation) and `val2` (Purpose of research). If the list names
   the queue's fields differently, `AskForm::QUEUE_FIELDS` needs to follow.

## Using the form

```php
use Drupal\libanswers\Form\AskForm;

$form = \Drupal::formBuilder()->getForm(AskForm::class, 2616, 'ask-special-collections');
```

The second argument is the queue; the third, the fragment the page returns
to after a post without JavaScript. A theme overrides
`libanswers-ask-form.html.twig` for its own markup.

A script can post the form in the background (`fetch`, the form's own
fields) with the header `X-LibAnswers-Fetch: 1` and gets JSON:

- `200 {"ok": true}`: the ticket exists.
- `422 {"errors": {"pemail": "…"}}`: fields to fix (or Honeypot caught it).
- `502 {"ok": false}`: LibAnswers refused it; the reason is in the log
  (`drush watchdog:show --type=libanswers`).

## Notes

- Honeypot's hidden field only, not its time limit: the time limit turns off
  page caching wherever the form appears.
- A token is fetched for each question; nothing is stored.
