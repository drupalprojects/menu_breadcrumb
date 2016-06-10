<?php

namespace Drupal\menu_breadcrumb\Form;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SettingsForm
 * @package Drupal\menu_breadcrumb\Form
 */
class SettingsForm extends ConfigFormBase {

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $moduleHandler) {
    parent::__construct($config_factory);
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['menu_breadcrumb.settings'];
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  public function buildForm(array $form = [], FormStateInterface $form_state) {
    $config = $this->config('menu_breadcrumb.settings');
    $form['determine_menu'] = [
      '#type' => 'checkbox',
      '#title' => t('Use menu the page belongs to for the breadcrumb.'),
      '#description' => t('By default, Drupal builds breadcrumb on path basis. If you want to use the menu the active page belongs to for the breadcrumb, enable this option.'),
      '#default_value' => $config->get('determine_menu'),
    ];

    $form['disable_admin_page'] = [
      '#type' => 'checkbox',
      '#title' => t('Disable for admin pages'),
      '#description' => t('Do not build menu-based breadcrumbs for admin pages.'),
      '#default_value' => $config->get('disable_admin_page'),
    ];

    $form['append_current_page'] = [
      '#type' => 'checkbox',
      '#title' => t('Append current page to breadcrumb'),
      '#description' => t('Choose whether or not the current page should be included in the breadcrumb.'),
      '#default_value' => $config->get('append_current_page'),
    ];

    $form['current_page_as_link'] = [
      '#type' => 'checkbox',
      '#title' => t('Current page as link'),
      '#description' => t('Choose whether or not the appended current page title should be a link.'),
      '#default_value' => $config->get('current_page_as_link'),
      '#states' => [
        'visible' => [
          ':input[name="append_current_page"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['hide_on_single_item'] = [
      '#type' => 'checkbox',
      '#title' => t('Hide the breadcrumb if the breadcrumb only contains the link to the front page.'),
      '#description' => t('Choose whether or not the breadcrumb should be hidden if the breadcrumb only contains a link to the front page (<em>Home</em>.).'),
      '#default_value' => $config->get('hide_on_single_item'),
    ];

    $form['remove_home'] = [
      '#type' => 'checkbox',
      '#title' => t('Remove "Home" link'),
      '#description' => t('Removes "Home" link to the front page, in case you already have one.'),
      '#default_value' => $config->get('remove_home'),
    ];

    $form['home_as_site_name'] = [
      '#type' => 'checkbox',
      '#title' => t('Use site name for "Home" link'),
      '#default_value' => $config->get('home_as_site_name'),
      '#states' => [
        'visible' => [
          ':input[name="remove_home"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['include_exclude'] = [
      '#type' => 'fieldset',
      '#title' => t('Enable / Disable Menus'),
      '#description' => t('The breadcrumb will be generated from the first "enabled" menu that contains a menu item for the page. Re-order the list to change the priority of each menu.'),
    ];

    $form['include_exclude']['note_about_navigation'] = [
      '#markup' => '<p class="description">' . t("Note: If none of the enabled menus contain an item for a given page, Drupal will look in the 'Navigation' menu by default, even if it is 'disabled' here.") . '</p>',
    ];

    // Orderable list of menu selections.
    $form['include_exclude']['menu_breadcrumb_menus'] = [
      '#type' => 'table',
      '#header' => [t('Menu'), t('Enabled'), t('Weight')],
      '#empty' => t('There is no menus yet.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'menus-order-weight',
        ],
      ],
    ];

    foreach ($this->getSortedMenus() as $menu_name => $menu_config) {

      $form['include_exclude']['menu_breadcrumb_menus'][$menu_name] = [
        '#attributes' => [
          'class' => ['draggable'],
        ],
        '#weight' => $menu_config['weight'],
        'title' => [
          '#plain_text' => $menu_config['label'],
        ],
        'enabled' => [
          '#type' => 'checkbox',
          '#default_value' => $menu_config['enabled'],
        ],
        'weight' => [
          '#type' => 'weight',
          '#default_value' => $menu_config['weight'],
          '#attributes' => ['class' => ['menus-order-weight']],
        ],
      ];
    }

    $form['include_exclude']['description'] = [
      '#prefix' => '<p class="description">',
      '#suffix' => '</p>',
      '#markup' => t('<strong>Default setting</strong> is not a real menu - it defines the default position and enabled status for future menus. If it is "enabled", Menu Breadcrumb will automatically consider newly-added menus when establishing breadcrumbs. If it is disabled, new menus will not be used for breadcrumbs until they have explicitly been enabled here.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('menu_breadcrumb.settings')
      ->set('determine_menu', (boolean) $form_state->getValue('determine_menu'))
      ->set('disable_admin_page', (boolean) $form_state->getValue('disable_admin_page'))
      ->set('append_current_page', (boolean) $form_state->getValue('append_current_page'))
      ->set('current_page_as_link', (boolean) $form_state->getValue('current_page_as_link'))
      ->set('hide_on_single_item', (boolean) $form_state->getValue('hide_on_single_item'))
      ->set('remove_home', (boolean) $form_state->getValue('remove_home'))
      ->set('home_as_site_name', (boolean) $form_state->getValue('home_as_site_name'))
      ->set('menu_breadcrumb_menus', $form_state->getValue('menu_breadcrumb_menus'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'menu_breadcrumb_settings';
  }

  /**
   * Returns array of menus with properties (enabled, weight, label) sorted by
   * weight.
   */
  protected function getSortedMenus() {
    $menu_enabled = $this->moduleHandler->moduleExists('menu_ui');
    $menus = $menu_enabled ? menu_ui_get_menus() : menu_list_system_menus();
    $menu_breadcrumb_menus = $this->config('menu_breadcrumb.settings')
      ->get('menu_breadcrumb_menus');

    foreach ($menus as $menu_name => &$menu) {
      if (!empty($menu_breadcrumb_menus[$menu_name])) {
        $menu = $menu_breadcrumb_menus[$menu_name] + ['label' => $menu];
      }
      else {
        $menu = ['weight' => 0, 'enabled' => 0, 'label' => $menu];
      }
    }

    uasort($menus, function ($a, $b) {
      return SortArray::sortByWeightElement($a, $b);
    });

    return $menus;
  }
}
