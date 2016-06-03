<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Behat\Behat\Context\TranslatableContext as Context;

/**
 * For storing languages created during testing.
 */
class LanguageCache extends CacheBase {

  /**
   * {@InheritDoc}.
   *
   * WARNING: leverages the D7 api to directly retrieve a result.  This
   * eventually needs to be rewritten to use drivers.
   */
  public function get($key, Context &$context) {
    if (!property_exists($this->cache, $key)) {
      throw new \Exception(sprintf("%s::%s: No language result found for key %s", __CLASS__, __FUNCTION__, $key));
    }
    $languages = language_list();
    if (!isset($languages[$key])) {
      throw new \Exception(sprintf("%s::%s: No result found for alias %s.  Language list: %s", __CLASS__, __FUNCTION__, $key, print_r(array_keys($languages))));
    }
    return language_list($key);
  }

  /**
   * {@InheritDoc}.
   */
  public function clean(Context &$context) {
    if ($this->count() === 0) {
      return TRUE;
    }
    foreach ($this->cache as $term) {
      $context->getDriver()->languageDelete($term);
    }
    return $this->resetCache();
  }

}
