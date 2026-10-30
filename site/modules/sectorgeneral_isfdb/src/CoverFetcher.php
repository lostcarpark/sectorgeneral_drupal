<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;

/**
 * Adds or upgrades cover images from the image addresses in the ISFDB copy.
 *
 * Reads each publication's image address from the local ISFDB database, so
 * no ISFDB pages or API are queried; only the image files are downloaded,
 * from wherever ISFDB says they are hosted.
 *
 * The same image is never added twice: an image already in the library is
 * reused when its download address matches, or, after downloading, when its
 * SHA-1 fingerprint matches. Every change is logged so it can be undone.
 */
class CoverFetcher {

  /**
   * A replacement must have at least this many times the current pixels.
   */
  public const LARGER = 1.1;

  protected const LOG = 'sectorgeneral_isfdb.cover_log';

  /**
   * Sizes of images downloaded but not used, by address.
   */
  protected const SKIPPED = 'sectorgeneral_isfdb.cover_skipped';

  protected float $lastDownload = 0;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected Merger $merger,
  ) {}

  /**
   * Fingerprints existing cover images that don't have one yet.
   *
   * @return int
   *   The number fingerprinted.
   */
  public function fingerprintLibrary(): int {
    $count = 0;
    $media = $this->entityTypeManager->getStorage('media')->loadByProperties(['bundle' => 'cover_image']);
    foreach ($media as $item) {
      $file = $item->get('field_media_image')->entity;
      if ($item->get('field_image_hash')->isEmpty() && $file && is_readable($file->getFileUri())) {
        $item->set('field_image_hash', sha1_file($file->getFileUri()));
        $item->save();
        $count++;
      }
    }
    return $count;
  }

  /**
   * Items with an ISFDB ID and an ISFDB image address.
   *
   * @return array[]
   *   Each with node, url and pub_id.
   */
  public function candidates(array $types, int $limit = 0): array {
    $isfdb = Database::getConnection('default', 'isfdb');
    $nodes = $this->entityTypeManager->getStorage('node');
    $ids = $nodes->getQuery()->accessCheck(FALSE)
      ->condition('type', $types, 'IN')
      ->exists('field_isfdb_id')
      ->sort('nid')
      ->execute();
    $found = [];
    foreach ($nodes->loadMultiple($ids) as $node) {
      $id = (int) $node->get('field_isfdb_id')->value;
      if ($node->bundle() === 'anthology') {
        // Anthologies hold a title ID: use its earliest publication with a cover.
        $row = $isfdb->query("SELECT p.pub_id, p.pub_frontimage FROM {pub_content} pc INNER JOIN {pubs} p ON p.pub_id = pc.pub_id
          WHERE pc.title_id = :id AND p.pub_frontimage <> '' ORDER BY CAST(p.pub_year AS CHAR), p.pub_id LIMIT 1", [':id' => $id])->fetchObject();
      }
      else {
        $row = $isfdb->query("SELECT pub_id, pub_frontimage FROM {pubs} WHERE pub_id = :id AND pub_frontimage <> ''", [':id' => $id])->fetchObject();
      }
      $url = $row ? $this->imageUrl($row->pub_frontimage) : NULL;
      if ($url) {
        $found[] = ['node' => $node, 'url' => $url, 'pub_id' => (int) $row->pub_id];
        if ($limit && count($found) >= $limit) {
          break;
        }
      }
    }
    return $found;
  }

  /**
   * Adds or upgrades one item's cover.
   *
   * @return string
   *   What happened: added, replaced, kept, current, reused or failed.
   */
  public function process(NodeInterface $node, string $url, string $source = 'ISFDB', string $page = '', ?float $delay = NULL): string {
    $current = $node->get('field_cover')->entity;
    $media = $this->findMedia('field_image_url', $url);
    $created = FALSE;

    // Images downloaded before but too small to use, by address.
    $too_small = $this->state->get(self::SKIPPED, []);
    if (!$media && $current && isset($too_small[$url]) && !$this->isLarger($too_small[$url][0], $too_small[$url][1], $current)) {
      return 'kept';
    }

    if (!$media) {
      $data = $this->download($url, $delay);
      $size = @getimagesizefromstring($data);
      if (!$size) {
        throw new \RuntimeException("Not an image: $url");
      }
      $media = $this->findMedia('field_image_hash', sha1($data));
      if ($media && $media->get('field_image_url')->isEmpty()) {
        // Remember the address, so the next run needn't download it again.
        $media->set('field_image_url', ['uri' => $url]);
        $media->save();
      }
      if (!$media) {
        // Don't add an image that wouldn't be used.
        if ($current && !$this->isLarger($size[0], $size[1], $current)) {
          $too_small[$url] = [$size[0], $size[1]];
          $this->state->set(self::SKIPPED, $too_small);
          return 'kept';
        }
        $media = $this->createMedia($node, $url, $source, $page, $data, $size);
        $created = TRUE;
      }
    }

    if ($current && (int) $current->id() === (int) $media->id()) {
      return 'current';
    }
    if ($current && !$this->isLarger((int) $media->get('field_media_image')->width, (int) $media->get('field_media_image')->height, $current)) {
      return 'kept';
    }
    $node->set('field_cover', $media->id());
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage(($current ? 'Cover replaced by a larger image' : 'Cover image') . " from $source: $page");
    $node->save();
    $this->log($node, $current, $media, $created);
    return $created ? ($current ? 'replaced' : 'added') : 'reused';
  }

  /**
   * Gives books without a cover the cover of their earliest English edition.
   *
   * @return int
   *   The number of books given a cover.
   */
  public function fillBookCovers(): int {
    $count = 0;
    $nodes = $this->entityTypeManager->getStorage('node');
    $english = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'language', 'name' => 'English']);
    $english = $english ? (int) array_key_first($english) : 0;
    foreach ($nodes->loadByProperties(['type' => 'novel']) as $book) {
      if (!$book->get('field_cover')->isEmpty()) {
        continue;
      }
      $editions = array_filter($nodes->loadByProperties(['type' => 'edition', 'field_novel' => $book->id()]),
        fn($e) => !$e->get('field_cover')->isEmpty() && (int) $e->get('field_language')->target_id === $english);
      usort($editions, fn($a, $b) => ((int) $a->get('field_year')->value ?: 9999) <=> ((int) $b->get('field_year')->value ?: 9999));
      if ($edition = reset($editions)) {
        $media = $edition->get('field_cover')->entity;
        $book->set('field_cover', $media->id());
        $book->setNewRevision(TRUE);
        $book->setRevisionLogMessage('Cover from its earliest English edition.');
        $book->save();
        $this->log($book, NULL, $media, FALSE);
        $count++;
      }
    }
    return $count;
  }

  /**
   * Switches covers to a larger copy of the same picture within each book's
   * family: the book and the editions of it.
   *
   * Only pictures used in the same family are compared, because different
   * issues of a magazine, or books in one series, can look alike. An
   * edition keeps a cover fetched for that publication; only old-site
   * thumbnails on editions are upgraded.
   *
   * @return array[]
   *   The changes, each with node, from, to and similarity.
   */
  public function upgradeSimilar(float $threshold, bool $dry_run, float $edge_threshold = 0.7): array {
    $nodes = $this->entityTypeManager->getStorage('node');
    $pictures = [];
    $picture = function (MediaInterface $media) use (&$pictures) {
      $id = (int) $media->id();
      if (!array_key_exists($id, $pictures)) {
        $file = $media->get('field_media_image')->entity;
        $pictures[$id] = $file ? Isfdb::picture($file->getFileUri()) : NULL;
      }
      return $pictures[$id];
    };
    $area = fn(MediaInterface $m) => (int) $m->get('field_media_image')->width * (int) $m->get('field_media_image')->height;
    $ratio = fn(MediaInterface $m) => ((int) $m->get('field_media_image')->width ?: 1) / ((int) $m->get('field_media_image')->height ?: 1);

    $changes = [];
    foreach ($nodes->loadByProperties(['type' => 'novel']) as $book) {
      $family = [$book] + $nodes->loadByProperties(['type' => 'edition', 'field_novel' => $book->id()]);
      $covers = [];
      foreach ($family as $member) {
        if ($media = $member->get('field_cover')->entity) {
          $covers[(int) $media->id()] = $media;
        }
      }
      foreach ($family as $member) {
        // Re-read: an earlier change in this run may have updated it.
        $current = $member->get('field_cover')->entity;
        if (!$current || !($mine = $picture($current))) {
          continue;
        }
        // An edition's cover fetched for that publication (from ISFDB or
        // Open Library) shows that edition, with its own design and text;
        // only old-site thumbnails are upgraded from another edition. A book
        // can take the best copy from any of its editions.
        $source = (string) $current->get('field_image_source')->entity?->label();
        if ($member->bundle() === 'edition' && $source !== '' && $source !== 'James White Information (original site)') {
          continue;
        }
        $best = NULL;
        $best_score = 0.0;
        foreach ($covers as $candidate) {
          if ((int) $candidate->id() === (int) $current->id() || $area($candidate) <= $area($current) * self::LARGER) {
            continue;
          }
          if (abs($ratio($candidate) - $ratio($current)) > 0.12 * $ratio($current) || !($theirs = $picture($candidate))) {
            continue;
          }
          $score = Isfdb::pictureSimilarity($mine, $theirs);
          if ($score['brightness'] >= $threshold && $score['edges'] >= $edge_threshold && (!$best || $area($candidate) > $area($best))) {
            [$best, $best_score] = [$candidate, $score];
          }
        }
        if (!$best) {
          continue;
        }
        $changes[] = ['node' => $member, 'from' => $current, 'to' => $best, 'similarity' => $best_score];
        if (!$dry_run) {
          $member->set('field_cover', $best->id());
          $member->setNewRevision(TRUE);
          $member->setRevisionLogMessage(sprintf('Cover switched to a larger copy of the same picture (media %d; brightness %.2f, edges %.2f).', $best->id(), $best_score['brightness'], $best_score['edges']));
          $member->save();
          $this->log($member, $current, $best, FALSE);
        }
      }
    }
    return $changes;
  }

  /**
   * Restores every cover the command changed and removes the images it
   * added, unless something else now uses them.
   *
   * @return int[]
   *   [items restored, images deleted].
   */
  public function undo(): array {
    $nodes = $this->entityTypeManager->getStorage('node');
    $media = $this->entityTypeManager->getStorage('media');
    $restored = 0;
    $created = [];
    foreach (array_reverse($this->state->get(self::LOG, [])) as $entry) {
      $node = $nodes->load($entry['nid']);
      // Only undo covers nobody has changed since.
      if ($node && (int) $node->get('field_cover')->target_id === $entry['new']) {
        $node->set('field_cover', $entry['previous']);
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage('ISFDB cover change undone.');
        $node->save();
        $restored++;
      }
      if ($entry['created']) {
        $created[] = $entry['new'];
      }
    }
    $deleted = 0;
    foreach (array_unique($created) as $mid) {
      if (($item = $media->load($mid)) && !$this->merger->countReferences('media', (int) $mid)) {
        $item->delete();
        $deleted++;
      }
    }
    $this->state->delete(self::LOG);
    return [$restored, $deleted];
  }

  /**
   * The image address from an ISFDB image field.
   *
   * The field may hold "image URL|page the image was credited to".
   */
  public function imageUrl(?string $value): ?string {
    $url = trim(explode('|', Isfdb::text($value))[0]);
    return preg_match('#^https?://#i', $url) ? $url : NULL;
  }

  protected function isLarger(int $width, int $height, MediaInterface $current): bool {
    $item = $current->get('field_media_image')->first();
    $have = (int) $item?->width * (int) $item?->height;
    return $have === 0 || $width * $height > $have * self::LARGER;
  }

  protected function findMedia(string $field, string $value): ?MediaInterface {
    $property = $field === 'field_image_url' ? 'field_image_url.uri' : $field;
    $found = $this->entityTypeManager->getStorage('media')->loadByProperties(['bundle' => 'cover_image', $property => $value]);
    return $found ? reset($found) : NULL;
  }

  /**
   * Downloads an image, politely: waiting between requests and saying who
   * is asking.
   */
  public function download(string $url, ?float $delay = NULL): string {
    $delay ??= (float) $this->configFactory->get('sectorgeneral_isfdb.settings')->get('image_delay');
    $wait = $this->lastDownload + $delay - microtime(TRUE);
    if ($wait > 0) {
      usleep((int) ($wait * 1e6));
    }
    $this->lastDownload = microtime(TRUE);
    $site = $this->configFactory->get('system.site');
    $agent = sprintf('SectorGeneralBibliography/1.0 (+%s; %s)', \Drupal::request()->getSchemeAndHttpHost(), $site->get('mail'));
    return (string) $this->httpClient->request('GET', $url, ['headers' => ['User-Agent' => $agent], 'timeout' => 30])->getBody();
  }

  protected function createMedia(NodeInterface $node, string $url, string $source_name, string $page, string $data, array $size): MediaInterface {
    $dir = 'public://covers/isfdb';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    $name = preg_replace('/[^a-z0-9._-]+/', '-', strtolower(basename(parse_url($url, PHP_URL_PATH) ?: 'cover.jpg')));
    // Some image URLs have no extension, and the image field rejects files
    // without one, so take it from the image data.
    if (!pathinfo($name, PATHINFO_EXTENSION) && !empty($size[2])) {
      $name .= image_type_to_extension($size[2]);
    }
    $uri = $this->fileSystem->saveData($data, "$dir/$name", FileExists::Rename);
    $file = $this->entityTypeManager->getStorage('file')->create(['uri' => $uri, 'status' => 1, 'uid' => 1]);
    $file->save();
    $source = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'image_source', 'name' => $source_name]);
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => 'cover_image',
      'name' => $node->label() . ' cover',
      'uid' => 1,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $node->label() . ' cover', 'width' => $size[0], 'height' => $size[1]],
      'field_image_source' => $source ? array_key_first($source) : NULL,
      'field_source_url' => $page ? ['uri' => $page] : NULL,
      'field_image_url' => ['uri' => $url],
      'field_image_hash' => sha1($data),
    ]);
    $media->save();
    return $media;
  }

  protected function log(NodeInterface $node, ?MediaInterface $previous, MediaInterface $new, bool $created): void {
    $log = $this->state->get(self::LOG, []);
    $log[] = ['nid' => (int) $node->id(), 'previous' => $previous ? (int) $previous->id() : NULL, 'new' => (int) $new->id(), 'created' => $created];
    $this->state->set(self::LOG, $log);
  }

}
