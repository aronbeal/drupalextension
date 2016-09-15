<?php

namespace Drupal\DrupalExtension\Context\Cache;

/**
 * A simple class to store globally unique aliases to specific items.
 */
class AliasCache extends ReferentialCache {
  const ALIAS_KEY_PREFIX   = '@';
  const ALIAS_VALUE_PREFIX = '@:';

  /**
   * Extracts alias names from the parameters of the passed object.
   *
   * Looks for a defined alias as a property of the passed object.  Unsets it
   * if found, and returns whatever the alias stored there is.  Note that
   * this function ONLY extracts field name keys - field values are handled
   * by the convertAliasValues function elsewhere in this class.
   *
   * @param object &$o
   *   An object containing keys=>values corresponding to field data for
   *   an object that is soon to be created for testing.
   *
   * @return string|NULL
   *         The string alias if one was found, or NULL if no alias key was
   *         present.
   */
  public static function extractAliasKey(&$o) {
    if (!is_object($o)) {
      throw new \Exception(sprintf("%s::%s: Wrong argument type (%s) passed.", __CLASS__, __FUNCTION__, gettype($o)));
    }
    // TODO: check for multiple aliases set on one object.
    $alias = NULL;
    if (is_object($o)) {
      if (property_exists($o, self::ALIAS_KEY_PREFIX)) {
        $alias = $o->{self::ALIAS_KEY_PREFIX};
        unset($o->{self::ALIAS_KEY_PREFIX});
      }
    }
    elseif (is_array($o)) {
      if (array_key_exists(self::ALIAS_KEY_PREFIX, $o)) {
        $alias = $o[self::ALIAS_KEY_PREFIX];
        unset($o[self::ALIAS_KEY_PREFIX]);
      }
    }
    else {
      throw new \Exception(sprintf("%s::%s: Invalid argument type: %s", __CLASS__, __FUNCTION__, gettype($o)));
    }
    return $alias;
  }

  /**
   * Returns the cache name where the object is stored.
   *
   * Should only ever be called by RawDrupalContext.  TODO: figure out an
   * enforcement mechanism for this.
   *
   * @param string $alias
   *   The alias for the stored object.
   *
   * @return string
   *   The cache name where the object is stored.
   *
   * @throws \Exception
   *   If the alias does not exist, or the cache where it indicates its data
   *   is stored does not exist.
   */
  public function getCache($alias) {
    if (!property_exists($this->cache, $alias)) {
      throw new \Exception(sprintf("%s::%s: No result found for key %s", __CLASS__, __FUNCTION__, $key));
    }
    $o = $this->cache->{$alias};
    return $o->cache;
  }

  /**
   * Transforms alias values into actual values.
   *
   * Converts alias values passed in from a feature into the value of the
   * object and field the alias references.  Alias values begin with the
   * character sequence '@:', followed by an alias name and a field name.
   * For example, '@:test_user/uid' would refer to a cached object with
   * the alias 'test_user', and would convert to whatever value was stored
   * in the uid field for the object at that alias.
   *
   * @param object $values
   *   The parameterized object that will be used to create a new Drupal object
   *   (node, user, what have you). This function is called primarily by the
   *   [X]Create methods found in RawDrupalContext, to transform values
   *   immediately prior to node creation.  It is important for outside
   *   callers that use aliases in their tables to invoke this function however,
   *   if they intend to circumvent functions like nodeCreate.
   */
  public function convertAliasValues(&$values, &$context) {
    // Translate dynamic values if present.
    if (empty($values)) {
      throw new \Exception(sprintf('%s::%s: An empty argument was passed.', get_class($this), __FUNCTION__));
    }
    if (!is_object($values)) {
      throw new \Exception(sprintf('%s::%s: Invalid argument type for function: %s', get_class($this), __FUNCTION__, gettype($values)));
    }
    foreach ($values as $field_name => $field_value) {
      if (!is_string($field_value)) {
        // We currently don't allow aliases to exist deeper than the first
        // level.
        continue;
      }
      // Aliases are of the form @:[alphanumeric]/[alphanumeric].  For instance,
      // '@:test_user/uid' would provide the user id of the user aliased by
      // 'test_user' in a previous scenario step.
      if (!preg_match('|' . self::ALIAS_VALUE_PREFIX . '\w+/\w+|', $field_value)) {
        // No aliases anywhere in the field value.
        continue;
      }
      // Explode to allow for multiple aliases.  Resolve each.  Note that
      // some may be aliases, while others may still be literal values.
      $unresolved_field_values = array_map('trim', explode(',', $field_value));
      $resolved_field_values = array();
      for ($i = 0; $i < count($unresolved_field_values); $i++) {
        if (!preg_match('|' . self::ALIAS_VALUE_PREFIX . '\w+/\w+|', $field_value)) {
          $resolved_field_values[$i] = $unresolved_field_values[$i];
          continue;
        }
        // Perfect match to our regex, so it is an alias.
        $confirmed_alias = $unresolved_field_values[$i];
        // This should map to a value in the alias cache.
        $confirmed_alias_with_field = str_replace(self::ALIAS_VALUE_PREFIX, '', $confirmed_alias);
        $av_components = explode('/', $confirmed_alias_with_field);
        if (count($av_components) < 2) {
          throw new \Exception(sprintf("%s::%s: Any alias passed as a value must have a field assigned to it.  The alias %s does not", get_class($this), __FUNCTION__, $confirmed_alias));
        }
        list($actual_alias, $referenced_field_name) = $av_components;
        $o = $this->get($actual_alias, $context);
        if (empty($o)) {
          throw new \Exception(sprintf('%s::%s: Attempt was made to dynamically reference the property of an item that was not yet created.', get_class($this), __FUNCTION__));
        }
        if (!property_exists($o, $referenced_field_name)) {
          $property_list = array_keys(get_object_vars($o));
          sort($property_list);
          throw new \Exception(sprintf("%s::%s: The field %s was  not found on the retrieved cache object: %s ", get_class($this), __FUNCTION__, $referenced_field_name, print_r($property_list, TRUE)));
        }
        $value = NULL;
        $entity_property_list = array_keys(get_object_vars($o));
        if (!in_array($referenced_field_name, $entity_property_list)) {
          throw new \Exception(sprintf("%s::%s: The field %s was  not found on the retrieved cache object.  Available properties: %s ", get_class($this), __FUNCTION__, $referenced_field_name, print_r($entity_property_list, TRUE)));
        }
        $resolved_field_values[$i] = $o->{$referenced_field_name};
      }
      // re-collapse the multiple values back to a single string.
      $values->{$field_name} = implode(', ', $resolved_field_values);
    }
  }

}
