<?php

include_once 'SellEntityProductHelperInterface.php';

/**
 * @class SellEntityProductHelper.
 */
abstract class SellEntityProductHelper implements SellEntityProductHelperInterface {

  protected $product_type;

  protected $entity_type;

  protected $entity_bundle;

  protected $entity_ref_field_name = NULL;

  protected $product_ref_field_name = NULL;

  public function __construct() {
    if (!isset($this->entity_ref_field_name) && !isset($this->product_ref_field_name)) {
      throw new Exception('Either $entity_ref_field_name or $product_ref_field_name member variable should not be empty.');
    }

    // Check is required member is here.
  }

  public function get_product_type() {
    return $this->product_type;
  }

  public function get_entity_type() {
    return $this->entity_type;
  }

  public function get_entity_bundle() {
    return $this->entity_bundle;
  }

  public function on_entity_create($entity) {
    $this->create_product($entity);
  }

  public function on_entity_update($entity) {
    /* Do nothing on update */
  }

  public function on_entity_delete($entity) {
    $product = $this->get_product_by_entity($entity);
    if ($product) {
      $this->disable_product($product);
    }
  }

  public function get_product_by_entity($entity) {
    $wrapper = entity_metadata_wrapper($this->entity_type, $entity);
    $efq = new EntityFieldQuery();
    $efq->entityCondition('entity_type', 'commerce_product')
      ->entityCondition('bundle', $this->product_type)
      ->propertyCondition('status', TRUE)
      ->fieldCondition($this->entity_ref_field_name, 'target_id', $wrapper->getIdentifier());

    $result = $efq->execute();
    if (isset($result['commerce_product'])) {
      $product_id = key($result['commerce_product']);
      $product = commerce_product_load($product_id);
      return $product;
    }

    return FALSE;
  }

  public function get_entity_by_product($product) {
    $wrapper = entity_metadata_wrapper('commerce_product', $product);

    if (!empty($this->entity_ref_field_name)) {
      $entity = $wrapper->{$this->entity_ref_field_name}->get();
      if ($entity) {
        return $entity;
      }
    }

    if (!empty($this->product_ref_field_name)) {
      $efq = new EntityFieldQuery();
      $efq->entityCondition('entity_type', $this->entity_type);
      if ($this->entity_bundle) {
        $efq->entityCondition('bundle', $this->entity_bundle);
      }
      $efq->fieldCondition($this->product_ref_field_name, 'target_id', $wrapper->getIdentifier());

      $result = $efq->execute();
      if (isset($result[$this->entity_type])) {
        $entity_id = key($result[$this->entity_type]);
        return entity_load($this->entity_type, $entity_id);
      }
    }
  }

  public function get_product_form($entity) {
    $product = $this->get_product_by_entity($entity);
    $form = reiscout_points_product_get_buy_form_by_sku($product->sku);
    return $form;
  }

  public function is_entity_purchased($entity, $account = NULL) {
    if (!isset($account)) {
      $account = $GLOBALS['user'];
    }

    // User has bought a product
    $product = $this->get_product_by_entity($entity);

    if (reiscout_points_product_is_purchased($product->sku, $account)) {
      return TRUE;
    }
    return FALSE;
  }

  protected function create_product($entity) {

    if (empty($this->entity_ref_field_name)) {
      throw new Exception('entity_ref_field_name should not be empty');
    }


    $entity_wrapper = entity_metadata_wrapper($this->entity_type, $entity);

    $product_type = $this->product_type;

    $product = commerce_product_new($product_type);
    $product->{$this->entity_ref_field_name}[LANGUAGE_NONE][0]['target_id'] = $entity_wrapper->getIdentifier();

    $points_product = reiscout_points_product_get($product_type);
    if ($points_product) {
      $price = reiscout_points_product_get_price($points_product['id']);
      if ($price === FALSE) {
        throw new Exception('Price for ' . $product_type . 'points product type is not set.');
      }
      $product->commerce_price[LANGUAGE_NONE][0] = array(
        'amount' => $price,
        'currency_code' => 'PTS',
      );
    } else {
      $product->commerce_price[LANGUAGE_NONE][0] = array(
        'amount' => 0,
        'currency_code' => 'USD',
      );
    }

    $product->title = $this->product_type . ' for '. $entity_wrapper->label();
    $product->sku =  $this->product_type . '-for-'. $this->get_entity_type() .'-'.$this->get_entity_bundle().'-'. $entity_wrapper->getIdentifier();

    commerce_product_save($product);

    return $product;
  }

  protected function disable_product($product) {
    try {
      $pw = entity_metadata_wrapper('commerce_product', $product);
      $pw->status->set(0);
      $pw->save();
    } catch (Exception $e) {
      throw new Exception($e->getMessage());
    }
  }
}