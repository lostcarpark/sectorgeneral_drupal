<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sectorgeneral_isfdb\Merger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms merging one duplicate into the item kept.
 */
class MergeConfirmForm extends ConfirmFormBase {

  protected ContentEntityInterface $keep;

  protected ContentEntityInterface $duplicate;

  protected string $kind;

  public function __construct(
    protected Merger $merger,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get(Merger::class), $container->get('entity_type.manager'));
  }

  public function getFormId() {
    return 'sectorgeneral_isfdb_merge_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $kind = NULL, ?int $keep = NULL, ?int $duplicate = NULL) {
    $storage = $this->entityTypeManager->getStorage($kind === 'nodes' ? 'node' : 'taxonomy_term');
    $a = $storage->load($keep);
    $b = $storage->load($duplicate);
    if (!$a || !$b || $keep === $duplicate) {
      throw new NotFoundHttpException();
    }
    [$this->kind, $this->keep, $this->duplicate] = [$kind, $a, $b];
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion() {
    return $this->t('Merge "@dup" into "@keep"?', ['@dup' => $this->duplicate->label(), '@keep' => $this->keep->label()]);
  }

  public function getDescription() {
    return $this->t('"@keep" (@type @keep_id) is kept with its own values; any empty fields are filled from "@dup" (@dup_id). Everything referring to "@dup" will point to "@keep", its address will redirect there, and "@dup" will be deleted. The merge is recorded in the revision history of "@keep".', [
      '@keep' => $this->keep->label(),
      '@dup' => $this->duplicate->label(),
      '@keep_id' => $this->keep->id(),
      '@dup_id' => $this->duplicate->id(),
      '@type' => $this->keep->bundle(),
    ]);
  }

  public function getConfirmText() {
    return $this->t('Merge');
  }

  public function getCancelUrl() {
    return Url::fromRoute('sectorgeneral_isfdb.duplicates');
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $label = $this->duplicate->label();
    $this->kind === 'nodes'
      ? $this->merger->mergeNodes($this->keep, $this->duplicate)
      : $this->merger->mergeTerms($this->keep, $this->duplicate);
    $this->messenger()->addStatus($this->t('Merged "@dup" into "@keep".', ['@dup' => $label, '@keep' => $this->keep->label()]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
