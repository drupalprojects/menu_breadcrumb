<?php

/**
 * @file
 * MenuBasedBreadcrumbBuilder.php
 */

namespace Drupal\menu_breadcrumb;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Class MenuBasedBreadcrumbBuilder
 * @package Drupal\menu_breadcrumb
 */
class MenuBasedBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected $menuActiveTrail;

  /**
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $linkManager;

  /**
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * Constructs the MenuBasedBreadcrumbBuilder.
   */
  public function __construct(ConfigFactoryInterface $configFactory, MenuActiveTrailInterface $menuActiveTrail, MenuLinkManagerInterface $linkManager, AdminContext $adminContext) {
    $this->configFactory = $configFactory;
    $this->config = $this->configFactory->get('menu_breadcrumb.settings');
    $this->menuActiveTrail = $menuActiveTrail;
    $this->linkManager = $linkManager;
    $this->adminContext = $adminContext;
  }

  /**
   * Whether this breadcrumb builder should be used to build the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return bool
   *   TRUE if this builder should be used or FALSE to let other builders
   *   decide.
   */
  public function applies(RouteMatchInterface $route_match) {
    return $this->config->get('determine_menu') &&
    !($this->config->get('disable_admin_page') &&
      $this->adminContext->isAdminRoute($route_match->getRouteObject()));
  }

  /**
   * Builds the breadcrumb.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Breadcrumb\Breadcrumb
   *   A breadcrumb.
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['url.path']);

    $menus = $this->config->get('menu_breadcrumb_menus');
    uasort($menus, function ($a, $b) {
      return SortArray::sortByWeightElement($a, $b);
    });

    $links = [];

    foreach ($menus as $menu_name => $params) {
      if (empty($params['enabled'])) {
        continue;
      }

      // Get active trail for current route for given menu.
      $trailIds = $this->menuActiveTrail->getActiveTrailIds($menu_name);
      $trailIds = array_filter($trailIds);
      // Skip if no links found.
      if (empty($trailIds)) {
        continue;
      }

      // Generate link for each menu item.
      foreach (array_reverse($trailIds) as $id) {
        $link = $this->linkManager->getInstance(['id' => $id]);
        $text = $link->getTitle();
        $url_object = $link->getUrlObject();
        if ($url_object->getRouteName() != "<front>") {
          $links[] = Link::fromTextAndUrl($text, $url_object);
        }
      }
      break;
    }

    if (!count($links) && $this->config->get('hide_on_single_item')) {
      return $breadcrumb;
    }

    if (!$this->config->get('remove_home')) {
      $label = $this->config->get('home_as_site_name') ?
        $this->configFactory->get('system.site')->get('name') :
        t('Home');
      $home = Link::createFromRoute($label, '<front>');
      array_unshift($links, $home);
    }

    /** @var Link $current */
    $current = array_pop($links);

    if ($this->config->get('append_current_page')) {
      if (!$this->config->get('current_page_as_link')) {
        $current->setUrl(new Url('<none>'));
      }

      array_push($links, $current);
    }

    return $breadcrumb->setLinks($links);
  }

}
