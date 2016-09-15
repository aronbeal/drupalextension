<?php
namespace Drupal\DrupalExtension\Hook\Scope;

use Behat\Behat\Context\Context;
use Behat\Testwork\Environment\Environment;

/**
 * Represents an Entity hook scope.
 */
abstract class BaseEntityScope implements EntityScope {

  /**
   * @var Environment
   */
  private $environment;

  /**
   * Context object.
   *
   * @var \Behat\Behat\Context\Context
   */
  private $context;

  /**
   * Entity object.
   */
  private $entity;

  /**
   * Initializes the scope.
   */
  public function __construct(Environment $environment, Context $context, $entity) {
    $this->context = $context;
    $this->entity = $entity;
    $this->environment = $environment;
  }

  /**
   * Returns the context.
   *
   * @return \Behat\Behat\Context\Context
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * Returns the entity object.
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnvironment() {
    return $this->environment;
  }

  /**
   * {@inheritdoc}
   */
  public function getSuite() {
    return $this->environment->getSuite();
  }

}
