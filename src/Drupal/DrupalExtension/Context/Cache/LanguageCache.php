<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * For storing languages created during testing.
 */
class LanguageCache extends CacheBase {

  /**
   * {@inheritdoc}
   *
   * WARNING: leverages the D7 api to directly retrieve a result.  This
   * eventually needs to be rewritten to use drivers.
   */
  public function get($key, RawDrupalContext &$context) {
    if (!property_exists($this->cache, $key)) {
      throw new \RuntimeException(sprintf('%s::%s: No language result found for key %s', __CLASS__, __FUNCTION__, $key));
    }
    $languages = language_list();
    if (!isset($languages[$key])) {
      throw new \RuntimeException(sprintf('%s::%s: No result found for alias %s.  Language list: %s', __CLASS__, __FUNCTION__, $key, print_r(array_keys($languages), TRUE)));
    }
    return language_list($key);
  }

  /**
   * {@inheritdoc}
   */
  public function clean(RawDrupalContext &$context) {
    if ($this->count() === 0) {
      return;
    }
    $languages = array_keys(get_object_vars($this->cache));
    foreach ($languages as $language) {
      if ($this->getCacheInstruction($language, 'noclean')) {
        continue;
      }
      $context->getDriver()->languageDelete($language);
    }
    $this->resetCache();
  }

}
