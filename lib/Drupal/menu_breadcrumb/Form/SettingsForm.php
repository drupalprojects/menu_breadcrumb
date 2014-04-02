<?php

namespace Drupal\menu_breadcrumb\Form;
use Drupal\Core\Form\ConfigFormBase;

class SettingsForm extends ConfigFormBase {

  public function getFormId() {
    return 'menu_breadcrumb_settings';
  }


  public function buildForm(array $form = array() , array &$form_state = array()) {
    $config = $this->config('menu_breadcrumb.menu');
    $form['determine_menu'] = array(
      '#type' => 'checkbox',
      '#title' => t('Use menu the page belongs to for the breadcrumb.'),
      '#description' => t('By default, Drupal will use the Navigation menu for the breadcrumb. If you want to use the menu the active page belongs to for the breadcrumb, enable this option.'),
      '#default_value' => $config->get('determine_menu') == NULL ? 1 : $config->get('determine_menu'),
    );


    $form['append_node_title'] = array(
      '#type' => 'checkbox',
      '#title' => t('Append page title to breadcrumb'),
      '#description' => t('Choose whether or not the page title should be included in the breadcrumb.'),
      '#default_value' => $config->get('append_node_title')
    );

    $form['append_node_url'] = array(
      '#type' => 'checkbox',
      '#title' => t('Appended page title as an URL.'),
      '#description' => t('Choose whether or not the appended page title should be an URL.'),
      '#default_value' => $config->get('append_node_url'),
    );

    $form['hide_on_single_item'] = array(
      '#type' => 'checkbox',
      '#title' => t('Hide the breadcrumb if the breadcrumb only contains the link to the front page.'),
      '#description' => t('Choose whether or not the breadcrumb should be hidden if the breadcrumb only contains a link to the front page (<em>Home</em>.).'),
      '#default_value' => $config->get('hide_on_single_item'),
    );

    $form['include_exclude'] = array(
      '#type' => 'fieldset',
      '#title' => t('Enable / Disable Menus'),
      '#description' => t('The breadcrumb will be generated from the first "enabled" menu that contains a menu item for the page. Re-order the list to change the priority of each menu.'),
    );

    $form['include_exclude']['note_about_navigation'] = array(
      '#markup' => '<p class="description">' . t("Note: If none of the enabled menus contain an item for a given page, Drupal will look in the 'Navigation' menu by default, even if it is 'disabled' here.") . '</p>',
    );

    // Orderable list of menu selections.
    $form['include_exclude']['menu_breadcrumb_menus'] = array(
      '#tree' => TRUE,
      '#theme' => 'menu_breadcrumb_menus_table',
    );

    $menus = _menu_breadcrumb_get_menus();
    $weight_delta = count($menus);

    foreach ($menus as $menu_name => $menu) {
      // Load menu titles.
      $title = !empty($menu['title']) ? $menu['title'] : $menu_name;

      if ($menu['type'] == 'menu') {
        $drupal_menu = menu_load($menu_name);
        if (!empty($drupal_menu['title'])) {
          $title = $drupal_menu['title'];
        }
      }


      $safe_id_prefix = 'edit-menu-breadcrumb-menus-'. menu_breadcrumb_html_id($menu_name);
      $form['include_exclude']['menu_breadcrumb_menus'][$menu_name] = array(
        'enabled' => array(
          '#type' => 'checkbox',
          '#id' => $safe_id_prefix .'-enabled',
          '#title' => '',
          '#default_value' => $menu['enabled'],
        ),
        'label' => array(
          '#value' => $menu_name,
        ),
        'weight' => array(
          '#type' => 'weight',
          '#default_value' => !empty($menu['weight']) ? (int) $menu['weight'] : 0,
          '#delta' => $weight_delta,
          '#id' => $safe_id_prefix .'-weight-wrapper',
        ),
        'type' => array(
          '#type' => 'value',
          '#value' => $menu['type'],
        ),
        'title' => array(
          '#type' => 'value',
          '#value' => $title,
        ),
        'title_display' => array(
          '#type' => 'markup',
          '#markup' => \Drupal\Component\Utility\String::checkPlain($title),
        ),
      );

      // Provide helpful title attributes for special menus.
      $title_field =& $form['include_exclude']['menu_breadcrumb_menus'][$menu_name]['title_display'];
      if ($menu['type'] == 'pattern') {
        $title_field['#value'] = t(
          '<span title="@title">@name <em>(@hint)</em></span>',
          array(
            '@title' => t("See 'Advanced' settings below."),
            '@name' => $title_field['#markup'],
            '@hint' => t('pattern'),
          )
        );
      }
      elseif ($menu['type'] == 'menu_breadcrumb_default_menu') {
        $title_field['#value'] = t(
          '<em><span title="@title">@text</span></em>',
          array(
            '@title' => t('Default setting for future menus.'),
            '@text' => t('Default setting (see below)'),
          )
        );
      }
    }

    $form['include_exclude']['description'] = array(
      '#type' => 'markup',
      '#prefix' => '<p class="description">',
      '#suffix' => '</p>',
      '#value' => t('<strong>Default setting</strong> is not a real menu - it defines the default position and enabled status for future menus. If it is "enabled", Menu Breadcrumb will automatically consider newly-added menus when establishing breadcrumbs. If it is disabled, new menus will not be used for breadcrumbs until they have explicitly been enabled here.'),
    );

    $form['include_exclude']['advanced'] = array(
      '#type' => 'fieldset',
      '#title' => t('Advanced'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    );

    $form['include_exclude']['advanced']['pattern_help'] = array(
      '#type' => 'markup',
      '#prefix' => '<p class="description">',
      '#suffix' => '</p>',
      '#value' => t("Enter regular expressions (one per line) to aggregate matching menu names into a single replacement title in the above list."),
    );

    $form['include_exclude']['advanced']['menu_patterns'] = array(
      '#type' => 'textarea',
      '#title' => t('Patterns'),
      '#default_value' => $config->get('menu_patterns'),
      '#description' => t("Syntax: /regex/title/<br/>e.g.: /^book-toc-\d+$/Books/"),
    );
    if (is_null($form['include_exclude']['advanced']['menu_patterns']['#default_value'])) {
      $form['include_exclude']['advanced']['menu_patterns']['#default_value'] = MENU_BREADCRUMB_REGEX_DEFAULT;
    }
    return parent::buildForm($form, $form_state);
  }


  public function validateForm(array &$form, array &$form_state) {
    $patterns =& $form_state['values']['menu_patterns'];

    // Filter white-space before saving patterns.
    $patterns = trim($patterns);
    $patterns = preg_replace('/\s*[\r\n]+\s*/', "\n", $patterns);

    // Check patterns against required syntax.
    if ($patterns) {
      foreach (explode("\n", $patterns) as $pattern) {
        if (!preg_match(MENU_BREADCRUMB_REGEX_MATCH, $pattern)) {
          $t_args = array(
            '%pattern' => $pattern,
            '%regex'   => MENU_BREADCRUMB_REGEX_MATCH
          );
          $this->setFormError('menu_patterns', $form_state, t("Invalid pattern syntax: %pattern does not match %regex", $t_args));
        }
      }
    }
    parent::validateForm($form, $form_state);
  }


  public function submitForm(array &$form, array &$form_state) {
    $config = $this->config('menu_breadcrumb.menu')
      ->set('pattern_matches_rebuild', TRUE)
      ->set('determine_menu', $form_state['values']['determine_menu'])
      ->set('append_node_title', $form_state['values']['append_node_title'])
      ->set('append_node_url', $form_state['values']['append_node_url'])
      ->set('hide_on_single_item', $form_state['values']['hide_on_single_item'])
      ->set('menu_patterns', $form_state['values']['menu_patterns'])
      ->set('menu_breadcrumb_menus', $form_state['values']['menu_breadcrumb_menus'])
      ->save();
    parent::submitForm($form, $form_state);
  }

}
