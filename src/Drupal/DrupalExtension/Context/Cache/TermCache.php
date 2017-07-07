<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * A simple class to store cached copies of created Drupal items, with indexing.
 */
class TermCache extends CacheBase {

  /**
   * {@inheritdoc}
   *
   * WARNING: leverages the D7 api to directly retrieve a result.  This
   * eventually needs to be rewritten to use drivers.
   */
  public function get($key, RawDrupalContext &$context) {
    if (!property_exists($this->cache, $key)) {
      throw new \Exception(sprintf("%s::%s: No term result found for key %s", __CLASS__, __FUNCTION__, $key));
    }
    return $context->getDriver()->getCore()->termLoad($key);
  }

  /**
   * {@inheritdoc}
   */
  public function clean(RawDrupalContext &$context) {
    if ($this->count() === 0) {
      return TRUE;
    }
    foreach ($this->cache as $tid) {
      $term = new \stdClass();
      $term->tid = $tid;
      $context->getDriver()->termDelete($term);
    }
    return $this->resetCache();
  }

}
