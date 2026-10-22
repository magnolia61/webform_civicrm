<?php

namespace Drupal\Tests\webform_civicrm\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests "Fields to Lock" with "Don't lock fields that are empty" on a contact
 * that is found by the matching rule during an anonymous submission.
 *
 * @group webform_civicrm
 */
#[RunTestsInSeparateProcesses]
final class ContactDedupeLockedFieldsTest extends WebformCivicrmTestBase {

  /**
   * Creates a matching rule on first name + email, without last name.
   */
  private function createFirstNameEmailRule(): void {
    $group = civicrm_api4('DedupeRuleGroup', 'create', [
      'checkPermissions' => FALSE,
      'values' => [
        'contact_type' => 'Individual',
        'threshold' => 20,
        'used' => 'General',
        'name' => 'FirstEmail',
        'title' => 'FirstEmail',
        'is_reserved' => FALSE,
      ],
    ])->first();
    foreach (['civicrm_contact' => 'first_name', 'civicrm_email' => 'email'] as $table => $field) {
      civicrm_api4('DedupeRule', 'create', [
        'checkPermissions' => FALSE,
        'values' => [
          'dedupe_rule_group_id' => $group['id'],
          'rule_table' => $table,
          'rule_field' => $field,
          'rule_weight' => 10,
        ],
      ]);
    }
  }

  /**
   * An anonymous submission must not overwrite the locked name of a matched
   * contact, while a new contact still gets the submitted name.
   */
  public function testLockedNameIsKeptOnMatchedContact() {
    $this->createFirstNameEmailRule();
    $existing = $this->utils->wf_civicrm_api('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Frederick',
      'last_name' => 'Pabst',
      'email' => 'frederick@pabst.io',
    ]);
    $cid = $existing['id'];

    $this->drupalLogin($this->adminUser);
    $this->drupalGet(Url::fromRoute('entity.webform.civicrm', [
      'webform' => $this->webform->id(),
    ]));
    $this->enableCivicrmOnWebform();
    $this->getSession()->getPage()->checkField('civicrm_1_contact_1_contact_middle_name');
    $this->assertSession()->checkboxChecked('civicrm_1_contact_1_contact_middle_name');
    $this->getSession()->getPage()->selectFieldOption('contact_1_number_of_email', 1);
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->checkboxChecked('civicrm_1_contact_1_email_email');
    $this->getSession()->getPage()->selectFieldOption('contact_1_settings_matching_rule', 'FirstEmail');
    $this->saveCiviCRMSettings();

    // Lock the name fields unless they are empty. The settings form saved the
    // webform in the browser, so reload it instead of using the cached copy.
    \Drupal::entityTypeManager()->getStorage('webform')->resetCache([$this->webform->id()]);
    $webform = Webform::load($this->webform->id());
    $key = 'civicrm_1_contact_1_contact_existing';
    $element = $webform->getElementDecoded($key);
    $this->assertNotEmpty($element, 'The existing contact element is on the webform.');
    $webform->setElementProperties($key, [
      '#widget' => 'hidden',
      '#default' => 'user',
      '#hide_fields' => ['name'],
      '#hide_method' => 'hide',
      '#no_hide_blank' => TRUE,
    ] + $element);
    $webform->save();
    $this->drupalLogout();

    // Anonymous visitor with the existing contact's first name and email.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertPageNoErrorMessages();
    $this->assertSession()->waitForField('First Name');
    $this->getSession()->getPage()->fillField('First Name', 'Frederick');
    $this->getSession()->getPage()->fillField('Middle Name', 'Someone');
    $this->getSession()->getPage()->fillField('Last Name', 'Someone-Else');
    $this->getSession()->getPage()->fillField('Email', 'frederick@pabst.io');
    $this->pressButtonOverride('Submit');
    $this->assertPageNoErrorMessages();
    $this->assertSession()->pageTextContains('New submission added to CiviCRM Webform Test.');

    $contact = $this->utils->wf_civicrm_api('Contact', 'getsingle', ['id' => $cid]);
    $this->assertEquals('Frederick', $contact['first_name']);
    $this->assertEquals('Pabst', $contact['last_name'], 'The locked last name of the matched contact was overwritten.');
    // The name counts as one group: the contact already has a name, so the
    // empty middle name is not filled with someone else's input either.
    $this->assertEmpty($contact['middle_name'] ?? '', 'The empty middle name of a named contact was filled in.');
    $this->assertEquals(1, $this->utils->wf_civicrm_api('Contact', 'getcount', ['email' => 'frederick@pabst.io']));

    // A visitor that matches nobody still creates a contact with a name.
    $this->drupalGet($this->webform->toUrl('canonical'));
    $this->assertSession()->waitForField('First Name');
    $this->getSession()->getPage()->fillField('First Name', 'Anna');
    $this->getSession()->getPage()->fillField('Last Name', 'Newcomer');
    $this->getSession()->getPage()->fillField('Email', 'anna@example.org');
    $this->pressButtonOverride('Submit');
    $this->assertPageNoErrorMessages();
    $new = $this->utils->wf_civicrm_api('Contact', 'get', ['email' => 'anna@example.org', 'sequential' => 1]);
    $this->assertEquals(1, $new['count']);
    $this->assertEquals('Anna', $new['values'][0]['first_name']);
    $this->assertEquals('Newcomer', $new['values'][0]['last_name']);
  }

}
