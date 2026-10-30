<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\sectorgeneral_isfdb\Library\LibrarySources;
use Drupal\sectorgeneral_isfdb\LibraryRecords;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists stored national library records for review, and applies approved
 * ones. Records are fetched with drush sg-isfdb:library-fetch.
 */
class LibraryReviewForm extends FormBase {

  public function __construct(
    protected LibraryRecords $records,
    protected LibrarySources $sources,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get(LibraryRecords::class), $container->get(LibrarySources::class));
  }

  public function getFormId(): string {
    return 'sectorgeneral_isfdb_library_review';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;
    $source = (string) $query->get('source', '');
    $status = (string) $query->get('status', 'open');
    $sources = $this->sources->all();
    $source = isset($sources[$source]) ? $source : '';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Records fetched from national library catalogues, and what applying each would do. Only empty fields are filled, and each edition credits the records it drew on. Approve the records to apply, or open one to point it at another edition or book. Nothing changes until approved records are applied. Fetch fresh records with <code>drush sg-isfdb:library-fetch</code>.') . '</p>',
    ];

    $fetched = [];
    foreach ($sources as $library) {
      $time = $this->records->lastFetched($library);
      $fetched[] = $this->t('@library: @when', ['@library' => $library->label(), '@when' => $time ? date('j M Y H:i', $time) : $this->t('never fetched')]);
    }
    $form['fetched'] = ['#markup' => '<p><small>' . $this->t('Last fetched') . ' — ' . implode(' · ', array_map('strval', $fetched)) . '</small></p>'];

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
      'source' => [
        '#type' => 'select',
        '#title' => $this->t('Library'),
        '#options' => ['' => $this->t('All')] + array_map(fn($library) => $library->label(), $sources),
        '#default_value' => $source,
      ],
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => ['open' => $this->t('Needing attention'), 'all' => $this->t('All')] + array_map(fn($label) => $this->t($label), LibraryRecords::STATUSES),
        '#default_value' => $status,
      ],
      'filter' => [
        '#type' => 'submit',
        '#value' => $this->t('Filter'),
        '#submit' => ['::filter'],
      ],
    ];

    $review = $this->records->review($source ?: NULL);
    $counts = array_count_values(array_column($review, 'status'));
    $wanted = match ($status) {
      'open' => ['pending', 'approved', 'skipped'],
      'all' => array_keys(LibraryRecords::STATUSES),
      default => [$status],
    };
    $order = array_flip(array_keys(LibraryRecords::STATUSES));
    $rows = array_filter($review, fn($row) => in_array($row['status'], $wanted, TRUE));
    uasort($rows, fn($a, $b) => [$order[$a['status']], $a['source']->id(), $a['record']['year'], $a['record']['title']] <=> [$order[$b['status']], $b['source']->id(), $b['record']['year'], $b['record']['title']]);

    $form['counts'] = [
      '#markup' => '<p>' . implode(' · ', array_map(fn($key, $label) => $this->t('@label: @count', ['@label' => $this->t($label), '@count' => $counts[$key] ?? 0]), array_keys(LibraryRecords::STATUSES), LibraryRecords::STATUSES)) . '</p>',
    ];

    $options = [];
    foreach ($rows as $key => $row) {
      $record = $row['record'];
      $plan = $row['plan'];
      $changes = [];
      foreach ($this->records->labelled($row['changes']) as $label => $text) {
        $changes[] = ['#markup' => '<strong>' . htmlspecialchars($label) . ':</strong> ' . nl2br(htmlspecialchars((string) $text))];
      }
      $target = match (TRUE) {
        isset($plan['edition']) => Link::fromTextAndUrl($plan['edition']->label(), $plan['edition']->toUrl())->toRenderable(),
        $plan['action'] === 'create' => ['#markup' => $this->t('New edition')],
        default => ['#markup' => htmlspecialchars((string) ($plan['reason'] ?? ''))],
      };
      if (!empty($row['decision']->target_edition) || !empty($row['decision']->target_book)) {
        $target['#suffix'] = ' <em>(' . $this->t('chosen by an editor') . ')</em>';
      }
      $options[$key] = [
        'library' => $row['source']->label(),
        'year' => $record['year'],
        'title' => ['data' => Link::fromTextAndUrl((string) $record['title'], Url::fromUri($record['url']))->toRenderable()],
        'book' => ['data' => isset($plan['book']) ? $plan['book']->toLink()->toRenderable() : ['#markup' => '']],
        'status' => $this->t(LibraryRecords::STATUSES[$row['status']]),
        'target' => ['data' => $target],
        'changes' => ['data' => ['#theme' => 'item_list', '#items' => $changes]],
        'review' => ['data' => Link::fromTextAndUrl($this->t('Review'), Url::fromRoute('sectorgeneral_isfdb.library_record', ['key' => $key], ['query' => $this->getDestinationArray()]))->toRenderable()],
      ];
    }
    $form['records'] = [
      '#type' => 'tableselect',
      '#header' => [
        'library' => $this->t('Library'),
        'year' => $this->t('Year'),
        'title' => $this->t('Title (library record)'),
        'book' => $this->t('Book'),
        'status' => $this->t('Status'),
        'target' => $this->t('Edition'),
        'changes' => $this->t('Would fill'),
        'review' => '',
      ],
      '#options' => $options,
      '#empty' => $this->t('No records.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['approve'] = ['#type' => 'submit', '#value' => $this->t('Approve selected'), '#submit' => ['::decide'], '#decision' => 'approved'];
    $form['actions']['reject'] = ['#type' => 'submit', '#value' => $this->t('Reject selected'), '#submit' => ['::decide'], '#decision' => 'rejected'];
    $form['actions']['reset'] = ['#type' => 'submit', '#value' => $this->t('Back to review'), '#submit' => ['::decide'], '#decision' => NULL];
    $form['actions']['apply'] = [
      '#type' => 'submit',
      '#value' => $source ? $this->t('Apply approved @library records (@count)', ['@library' => $sources[$source]->label(), '@count' => $counts['approved'] ?? 0]) : $this->t('Apply all approved records (@count)', ['@count' => $counts['approved'] ?? 0]),
      '#submit' => ['::apply'],
      '#button_type' => 'primary',
      '#disabled' => empty($counts['approved']),
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function filter(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('sectorgeneral_isfdb.library_review', [], ['query' => array_filter([
      'source' => $form_state->getValue('source'),
      'status' => $form_state->getValue('status'),
    ])]);
  }

  public function decide(array &$form, FormStateInterface $form_state): void {
    $decision = $form_state->getTriggeringElement()['#decision'];
    $selected = array_filter($form_state->getValue('records') ?? []);
    $review = $this->records->review();
    $done = 0;
    foreach ($selected as $key) {
      if (isset($review[$key])) {
        $this->records->setDecision($review[$key], $decision);
        $done++;
      }
    }
    $this->messenger()->addStatus(match ($decision) {
      'approved' => $this->formatPlural($done, 'Approved 1 record. Nothing has changed yet: click <em>Apply approved</em> to apply it.', 'Approved @count records. Nothing has changed yet: click <em>Apply approved</em> to apply them.'),
      'rejected' => $this->formatPlural($done, 'Rejected 1 record.', 'Rejected @count records.'),
      default => $this->formatPlural($done, 'Put 1 record back to review.', 'Put @count records back to review.'),
    });
    $form_state->setRedirect('sectorgeneral_isfdb.library_review', [], ['query' => $this->getRequest()->query->all()]);
  }

  public function apply(array &$form, FormStateInterface $form_state): void {
    $source = (string) $this->getRequest()->query->get('source', '');
    $results = $this->records->applyApproved($source ?: NULL);
    $counts = array_count_values($results);
    foreach (['created' => 'Created @count new editions.', 'updated' => 'Filled in @count editions.', 'unchanged' => '@count records had nothing left to change.', 'skipped' => '@count records could not be matched.'] as $result => $message) {
      if (!empty($counts[$result])) {
        $this->messenger()->addStatus($this->t($message, ['@count' => $counts[$result]]));
      }
    }
    if (!$results) {
      $this->messenger()->addWarning($this->t('No approved records to apply.'));
    }
    $form_state->setRedirect('sectorgeneral_isfdb.library_review', [], ['query' => $this->getRequest()->query->all()]);
  }

}
