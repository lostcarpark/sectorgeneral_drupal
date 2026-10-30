<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\sectorgeneral_isfdb\LibraryRecords;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One stored national library record: what the library says, what applying
 * it would do, and the editor's decision and choice of edition or book.
 */
class LibraryRecordForm extends FormBase {

  public function __construct(protected LibraryRecords $records) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get(LibraryRecords::class));
  }

  public function getFormId(): string {
    return 'sectorgeneral_isfdb_library_record';
  }

  public function title(string $key): string {
    $row = $this->records->row($key);
    return $row ? "{$row['record']['title']} ({$row['source']->label()}, {$row['record']['year']})" : 'Library record';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $key = ''): array {
    $row = $this->records->row($key) ?? throw new NotFoundHttpException();
    $form_state->set('key', $key);
    $record = $row['record'];
    $plan = $row['plan'];

    $people = [];
    foreach ($record['people'] as $field => $names) {
      $people[] = implode('; ', $names) . ' (' . str_replace(['field_', '_'], ['', ' '], $field) . ')';
    }
    $links = [Link::fromTextAndUrl($record['url'], Url::fromUri($record['url']))->toString()];
    foreach ($record['also'] ?? [] as $url) {
      $links[] = Link::fromTextAndUrl($url, Url::fromUri($url))->toString() . ' ' . $this->t('(duplicate, merged)');
    }
    $form['record'] = [
      '#type' => 'details',
      '#title' => $this->t('What @library says', ['@library' => $row['source']->label()]),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#rows' => array_map(fn($label, $value) => [['data' => $label, 'header' => TRUE], ['data' => ['#markup' => $value]]], ...$this->unzip([
          (string) $this->t('Record') => implode('<br>', array_map('strval', $links)),
          (string) $this->t('Kind') => htmlspecialchars((string) $record['kind']),
          (string) $this->t('Title') => htmlspecialchars((string) $record['title']),
          (string) $this->t('Original title') => htmlspecialchars((string) $record['original']),
          (string) $this->t('Publisher') => htmlspecialchars((string) $record['publisher']),
          (string) $this->t('Year') => htmlspecialchars((string) $record['year']),
          (string) $this->t('ISBN') => htmlspecialchars((string) $record['isbn']),
          (string) $this->t('Pages') => htmlspecialchars((string) $record['pages']),
          (string) $this->t('Edition / printing') => htmlspecialchars((string) $record['printing']),
          (string) $this->t('Series') => htmlspecialchars(trim($record['series'] . ' ' . $record['series_number'])),
          (string) $this->t('People') => htmlspecialchars(implode('; ', $people)),
        ])),
      ],
    ];

    $changes = [];
    foreach ($this->records->labelled($row['changes']) as $label => $text) {
      $changes[] = ['#markup' => '<strong>' . htmlspecialchars($label) . ':</strong> ' . nl2br(htmlspecialchars((string) $text))];
    }
    $form['plan'] = [
      '#type' => 'details',
      '#title' => $this->t('What applying it would do'),
      '#open' => TRUE,
      'status' => ['#markup' => '<p>' . $this->t('Status: %status', ['%status' => $this->t(LibraryRecords::STATUSES[$row['status']])]) . '</p>'],
      'action' => ['#markup' => '<p>' . match ($plan['action']) {
        'update' => $this->t('Fill in @edition, an edition of @book.', ['@edition' => $plan['edition']->toLink()->toString(), '@book' => $plan['book']?->toLink()->toString() ?? '?']),
        'create' => $this->t('Create a new edition of @book.', ['@book' => $plan['book']->toLink()->toString()]),
        default => $this->t('Nothing: @reason. Choose an edition or a book below.', ['@reason' => $plan['reason'] ?? '']),
      } . '</p>'],
      'changes' => ['#theme' => 'item_list', '#items' => $changes, '#empty' => $this->t('No empty fields left to fill.')],
    ];

    $decision = $row['decision'];
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $edition = !empty($decision->target_edition) ? $storage->load($decision->target_edition) : NULL;
    $book = !empty($decision->target_book) ? $storage->load($decision->target_book) : NULL;
    $form['target'] = [
      '#type' => 'radios',
      '#title' => $this->t('Apply it to'),
      '#options' => [
        'match' => $this->t('The edition or book matched automatically'),
        'edition' => $this->t('Another existing edition'),
        'book' => $this->t('A new edition of a book'),
      ],
      '#default_value' => $edition ? 'edition' : ($book ? 'book' : 'match'),
    ];
    $form['edition'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Edition'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['edition']],
      '#default_value' => $edition instanceof NodeInterface ? $edition : NULL,
      '#states' => ['visible' => [':input[name="target"]' => ['value' => 'edition']]],
    ];
    $form['book'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Book'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['novel']],
      '#default_value' => $book instanceof NodeInterface ? $book : NULL,
      '#states' => ['visible' => [':input[name="target"]' => ['value' => 'book']]],
    ];

    $raw = $this->records->raw($row['source']);
    foreach ([$record['id'], ...$record['also_ids'] ?? []] as $i => $id) {
      $form['raw'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Raw record @id, as stored', ['@id' => $id]),
        'data' => ['#markup' => '<pre style="white-space: pre-wrap; max-height: 30rem; overflow: auto">' . htmlspecialchars((string) ($raw[$id] ?? '')) . '</pre>'],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve and apply now'),
      '#button_type' => 'primary',
      '#decision' => 'apply',
      '#access' => $row['status'] !== 'done',
    ];
    $form['actions']['approve'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve (apply later)'),
      '#decision' => 'approved',
      '#access' => $row['status'] !== 'done',
    ];
    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject'),
      '#decision' => 'rejected',
      '#access' => $row['status'] !== 'rejected',
    ];
    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to review'),
      '#decision' => NULL,
      '#access' => in_array($row['status'], ['approved', 'rejected'], TRUE),
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $target = $form_state->getValue('target');
    $decision = $form_state->getTriggeringElement()['#decision'] ?? NULL;
    if (in_array($decision, ['apply', 'approved'], TRUE) && $target !== 'match' && !$form_state->getValue($target)) {
      $form_state->setErrorByName($target, $this->t('Choose the @what.', ['@what' => $target === 'edition' ? $this->t('edition') : $this->t('book')]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $key = $form_state->get('key');
    $row = $this->records->row($key);
    $decision = $form_state->getTriggeringElement()['#decision'] ?? NULL;
    $target = $form_state->getValue('target');
    $this->records->decide(
      $row,
      $decision === 'apply' ? 'approved' : $decision,
      $target === 'edition' ? (int) $form_state->getValue('edition') : NULL,
      $target === 'book' ? (int) $form_state->getValue('book') : NULL,
    );
    // Re-read: the choice of edition or book changes the plan.
    $row = $this->records->row($key);
    $title = $row['record']['title'];
    match ($decision) {
      'apply' => $this->reportApplied($key, $row),
      'approved' => $this->messenger()->addStatus($this->t('Approved %title. Nothing has changed yet: it will be applied with <em>Apply approved</em> on the list.', ['%title' => $title])),
      'rejected' => $this->messenger()->addStatus($this->t('Rejected %title. It will not be applied.', ['%title' => $title])),
      default => $this->messenger()->addStatus($this->t('%title is back to review.', ['%title' => $title])),
    };
    if (!$this->getRequest()->query->has('destination')) {
      $form_state->setRedirect('sectorgeneral_isfdb.library_review');
    }
  }

  /**
   * Applies the row and says what that did, naming the edition.
   */
  protected function reportApplied(string $key, array $row): void {
    if ($row['status'] !== 'approved') {
      $this->messenger()->addWarning($this->t('Nothing applied: %status.', ['%status' => $this->t(LibraryRecords::STATUSES[$row['status']])]));
      return;
    }
    $fields = implode(', ', array_map('mb_strtolower', array_keys($this->records->labelled($row['changes']))));
    $result = $this->records->apply($row);
    // A new edition is found by its credit now.
    $edition = $this->records->row($key)['plan']['edition'] ?? $row['plan']['edition'] ?? NULL;
    $link = $edition ? $edition->toLink()->toString() : $this->t('the edition');
    match ($result) {
      'created' => $this->messenger()->addStatus($this->t('Created @edition, with: @fields.', ['@edition' => $link, '@fields' => $fields])),
      'updated' => $this->messenger()->addStatus($this->t('Filled in @edition: @fields.', ['@edition' => $link, '@fields' => $fields])),
      'unchanged' => $this->messenger()->addStatus($this->t('@edition already had everything this record gives; nothing changed.', ['@edition' => $link])),
      default => $this->messenger()->addWarning($this->t('Nothing applied: no edition or book matched. Choose one and try again.')),
    };
  }

  /**
   * [label => value] as [labels, values], for array_map().
   */
  protected function unzip(array $pairs): array {
    $pairs = array_filter($pairs, fn($value) => $value !== '');
    return [array_keys($pairs), array_values($pairs)];
  }

}
