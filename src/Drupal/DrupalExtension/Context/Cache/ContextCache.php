<?php

namespace Drupal\DrupalExtension\Context\Cache;

use Drupal\DrupalExtension\Context\RawDrupalContext;

/**
 * For storing contexts created using the @BeforeScenario hook.
 */
class ContextCache extends CacheBase {

  /**
   * {@inheritdoc}
   *
   * This cache does not implement this interface method, and will throw an
   * exception if called.
   */
  public function addIndices() {
    throw new \Exception(get_class($this) . '::' . ": does not implement the " . __FUNCTION__ . " method.");
  }

  /**
   * Returns a list of all contexts stored.
   *
   * @param \Drupal\DrupalExtension\Context\RawDrupalContext $context
   *   The calling context.
   *
   * @return mixed
   *   Shortcut for $this->find(['all' => TRUE], $context);
   *
   * @throws \Exception
   */
  public function getAll(RawDrupalContext &$context) {
    return $this->find(['all' => TRUE], $context);
  }

  /**
   * {@inheritdoc}
   *
   * This cache does not implement this interface method, and will throw an
   * exception if called.
   */
  public function find(array $values, RawDrupalContext &$context) {
    $allowed_keys = array(
      'all' => function ($v, $context_names) {
        $results = array();
        foreach ($context_names as $name) {
          $results[] = $name;
        }
        return $results;
      },
      'name' => function ($v, $context_names) {
        $results = array();
        foreach ($context_names as $name) {
          if (stristr($name, $v) !== FALSE) {
            $results[] = $name;
          }
        }
        return $results;
      },
      'class' => function ($v, $context_names) {
        $results = array();
        foreach ($context_names as $name) {
          $short_name = explode('\\', $name);
          $short_name = end($short_name);
          if (stristr($short_name, $v) !== FALSE) {
            $results[] = $name;
          }
        }
        return $results;
      },
    );
    foreach ($values as $k => $v) {
      if (!is_scalar($v)) {
        throw new \Exception(sprintf("%s::%s: Does not yet support non-scalar finding", get_class($this), __FUNCTION__));
      }
    }
    if (count(array_intersect(array_keys($allowed_keys), array_keys($values))) === 0) {
      throw new \Exception(sprintf("%s::%s: The context cache does not support one or more of the passed keys.", get_class($this), __FUNCTION__));
    }
    $results = array();
    foreach ($allowed_keys as $k => $filtering_function) {
      if (isset($values[$k])) {
        // Store the results of filtering the cache with the provided key.
        $results[] = $filtering_function($values[$k], array_keys(get_object_vars($this->cache)));
      }
    }
    $results = array_reduce($results, function ($carry, $item) {
      if (is_null($carry)) {
        return $item;
      }
      return array_intersect($carry, $item);
    });
    if (!empty($results)) {
      foreach ($results as $k => &$v) {
        // Replace the string key with the actual context object.
        $v = $this->cache->{$v};
      }
    }
    return $results;
  }

}
