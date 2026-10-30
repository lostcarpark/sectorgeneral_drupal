<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The site's sections as cards, each with how much it holds.
 */
#[Block(
  id: 'sectorgeneral_explore',
  admin_label: new TranslatableMarkup('Explore the site'),
)]
class ExploreBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('database'));
  }

  public function build() {
    $count = fn(string $type) => (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('type', $type)->condition('status', 1)->count()->execute();
    [$translations, $languages] = $this->translations();

    $sections = [
      ['/books', $this->t('Books'), $this->t('@count books', ['@count' => $count('novel')]), $this->t("James White's novels and collections, with every edition and its cover art.")],
      ['/short-stories', $this->t('Short stories'), $this->t('@count stories', ['@count' => $count('short_story')]), $this->t('His short fiction, with synopses and where each story appeared.')],
      ['/translations', $this->t('Translations'), $this->t('@count editions in @languages languages', ['@count' => $translations, '@languages' => $languages]), $this->t('His books and stories in other languages, from German to Japanese.')],
      ['/magazines', $this->t('Magazines'), $this->t('@count issues', ['@count' => $count('magazine_issue')]), $this->t('The magazine issues his stories and serials appeared in.')],
      ['/anthologies', $this->t('Anthologies'), $this->t('@count anthologies', ['@count' => $count('anthology')]), $this->t('Collections of stories by several authors that include his work.')],
      ['/articles', $this->t('Articles'), NULL, $this->t('A selection of articles from notable authors.')],
      ['/bio-bibliography', $this->t('Bio-bibliography'), NULL, $this->t('An insightful bio-bibliography by Graham Andrews, a good friend of James White. Part of it first appeared in Interzone.')],
    ];
    $cards = [];
    foreach ($sections as [$path, $title, $figure, $description]) {
      $cards[] = [
        'url' => Url::fromUserInput($path)->toString(),
        'title' => $title,
        'figure' => $figure,
        'description' => $description,
      ];
    }
    $build = [
      '#type' => 'inline_template',
      '#template' => '<ul class="front-explore">{% for card in cards %}<li class="front-explore__card"><h3 class="front-explore__title"><a href="{{ card.url }}">{{ card.title }}</a></h3>{% if card.figure %}<p class="front-explore__figure">{{ card.figure }}</p>{% endif %}<p class="front-explore__description">{{ card.description }}</p></li>{% endfor %}</ul>',
      '#context' => ['cards' => $cards],
      '#attached' => ['library' => ['sectorgeneral/front']],
    ];
    (new CacheableMetadata())
      ->addCacheTags(['node_list', 'taxonomy_term_list:language'])
      ->applyTo($build);
    return $build;
  }

  /**
   * Published editions in languages other than English, and how many
   * languages they're in.
   *
   * @return int[]
   */
  protected function translations(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_language', 'l', 'l.entity_id = n.nid');
    $query->join('taxonomy_term_field_data', 't', 't.tid = l.field_language_target_id');
    $query->condition('n.type', 'edition')
      ->condition('n.status', 1)
      ->condition('t.name', 'English', '<>');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'editions');
    $query->addExpression('COUNT(DISTINCT t.tid)', 'languages');
    $row = $query->execute()->fetchAssoc();
    return [(int) $row['editions'], (int) $row['languages']];
  }

}
