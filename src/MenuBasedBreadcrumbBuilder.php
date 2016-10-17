<?php

namespace Drupal\menu_breadcrumb;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkManager;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * {@inheritdoc}
 */
class MenuBasedBreadcrumbBuilder implements BreadcrumbBuilderInterface {
  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  /**
   * The configuration object generator.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The menu active trail interface.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected $menuActiveTrail;

  /**
   * The menu link manager interface.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * The admin context generator.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * The title resolver.
   *
   * @var \Drupal\Core\Controller\TitleResolverInterface
   */
  protected $titleResolver;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * The Menu Breadcrumbs configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The menu where the current page or taxonomy match has taken place.
   *
   * @var string
   */
  private $menuName;

  /**
   * The menu trail leading to this match.
   *
   * @var string
   */
  private $menuTrail;

  /**
   * Node of current path if taxonomy attached.
   *
   * @var \Drupal\node\Entity\Node
   */
  private $taxonomyAttachment;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactory $config_factory,
    MenuActiveTrailInterface $menu_active_trail,
    MenuLinkManager $menu_link_manager,
    AdminContext $admin_context,
    TitleResolverInterface $titleResolver,
    RequestStack $requestStack
  ) {
    $this->configFactory = $config_factory;
    $this->menuActiveTrail = $menu_active_trail;
    $this->menuLinkManager = $menu_link_manager;
    $this->adminContext = $admin_context;
    $this->config = $this->configFactory->get('menu_breadcrumb.settings');
    $this->titleResolver = $titleResolver;
    $this->currentRequest = $requestStack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    // This may look heavyweight for applies() but we have to check all ways the
    // current path could be attached to the selected menus before turning over
    // breadcrumb building (and caching) to another builder.  Generally this
    // should not be a problem since it will then fall back to the system (path
    // based) breadcrumb builder which caches a breadcrumb no matter what.
    if (!$this->config->get('determine_menu')) {
      return FALSE;
    }
    // Don't breadcrumb the admin pages, if disabled on config options:
    if ($this->config->get('disable_admin_page') && $this->adminContext->isAdminRoute($route_match->getRouteObject())) {
      return FALSE;
    }
    // No route name means no active trail:
    $route_name = $route_match->getRouteName();
    if (!$route_name) {
      return FALSE;
    }

    // This might be a "node" with no fields, e.g. a route to a "revision" URL,
    // so we don't check for taxonomy fields on unfieldable nodes:
    $node_object = $route_match->getParameters()->get('node');
    $node_is_fieldable = $node_object instanceof FieldableEntityInterface;

    // Check each selected menu, in turn, until a menu or taxonomy match found:
    // then cache its state for building & caching in build() and exit.
    $menus = $this->config->get('menu_breadcrumb_menus');
    uasort($menus, function ($a, $b) {
      return SortArray::sortByWeightElement($a, $b);
    });
    foreach ($menus as $menu_name => $params) {

      // Look for current path on any enabled menu.
      if (!empty($params['enabled'])) {

        $trail_ids = $this->menuActiveTrail->getActiveTrailIds($menu_name);
        $trail_ids = array_filter($trail_ids);
        if ($trail_ids) {
          $this->menuName = $menu_name;
          $this->menuTrail = $trail_ids;
          $this->taxonomyAttachment = NULL;
          return TRUE;
        }
      }

      // Look for a "taxonomy attachment" by node field.
      if (!empty($params['taxattach']) && $node_is_fieldable) {

        // Check all taxonomy terms applying to the current page.
        foreach ($node_object->getFields() as $field) {
          if ($field->getSetting('target_type') == 'taxonomy_term') {

            // In general these entity references will support multiple
            // values so we check all terms in the order they are listed.
            foreach ($field->referencedEntities() as $term) {
              $url = $term->toUrl();
              $route_links = $this->menuLinkManager->loadLinksByRoute($url->getRouteName(), $url->getRouteParameters(), $menu_name);
              if (!empty($route_links)) {
                // Successfully found taxonomy attachment, so pass to build():
                // - the menu in in which we have found the attachment
                // - the effective menu trail of the taxonomy-attached node
                // - the node itself (in build() we will find its title & URL)
                $taxonomy_term_link = reset($route_links);
                $taxonomy_term_id = $taxonomy_term_link->getPluginId();
                $this->menuName = $menu_name;
                $this->menuTrail = $this->menuLinkManager->getParentIds($taxonomy_term_id);
                $this->taxonomyAttachment = $node_object;
                return TRUE;
              }
            }
          }
        }
      }
    }
    // No more menus to check...
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {

    $breadcrumb = new Breadcrumb();
    // Breadcrumbs accumulate in this array, with lowest index being the root
    // (i.e., the reverse of the assigned breadcrumb trail):
    $links = array();

    // Changing the <front> page will invalidate any breadcrumb generated here:
    $site_config = $this->configFactory->get('system.site');
    $breadcrumb->addCacheableDependency($site_config);

    // Changing any module settings will invalidate the breadcrumb:
    $breadcrumb->addCacheableDependency($this->config);

    // Changing the active trail of the current path, or taxonomy-attached path,
    // on this menu will invalidate this breadcrumb:
    $breadcrumb->addCacheContexts(['route.menu_active_trails:' . $this->menuName]);

    // Generate basic breadcrumb trail from active trail.
    // Keep same link ordering as Menu Breadcrumb (so also reverses menu trail)
    foreach (array_reverse($this->menuTrail) as $id) {
      $def = $this->menuLinkManager->getDefinition($id);
      $def_route_name = $def['route_name'];
      if ($def_route_name) {
        $url_object = Url::fromRoute($def_route_name, $def['route_parameters']);
      }
      else {
        // If no route, it's an external URI (issue 2750821):
        $url_object = Url::fromUri($def['url']);
      }
      $links[] = Link::fromTextAndUrl($def['title'], $url_object);
    }
    $this->addMissingCurrentPage($links, $route_match);

    // Create a breadcrumb for <front> which may be either added or replaced:
    $label = $this->config->get('home_as_site_name') ?
      $this->configFactory->get('system.site')->get('name') :
      t('Home');
    $home_link = Link::createFromRoute($label, '<front>');

    // The first link from the menu trail, being the root, may be the
    // <front> so first compare those two routes to see if they are identical.
    // (Though in general a link deeper in the menu could be <front>, in that
    // case it's arguable that the node-based pathname would be preferred.)
    $front_page = $site_config->get('page.front');
    $front_url = Url::fromUri("internal:$front_page");
    $first_url = $links[0]->getUrl();
    // If options are set to remove <front>, strip off that link, otherwise
    // replace it with a breadcrumb named according to option settings:
    if ($first_url->isRouted() && ($front_url->getRouteName() === $first_url->getRouteName()) && ($front_url->getRouteParameters() === $first_url->getRouteParameters())) {

      // According to the confusion hopefully cleared up in issue 2754521, the
      // sense of "remove_home" is slightly different than in Menu Breadcrumb:
      // we remove any match with <front> rather than replacing it.
      if ($this->config->get('remove_home')) {
        array_shift($links);
      }
      else {
        $links[0] = $home_link;
      }
    }
    else {
      // If trail *doesn't* begin with the home page, add it if that option set.
      if ($this->config->get('add_home')) {
        array_unshift($links, $home_link);
      }
    }

    if (!empty($links)) {
      $page_type = $this->taxonomyAttachment ? 'member_page' : 'current_page';
      // Display the last item of the breadcrumbs trail as the options indicate.
      /** @var Link $current */
      $current = array_pop($links);
      if ($this->config->get('append_' . $page_type)) {
        if (!$this->config->get($page_type . '_as_link')) {
          $current->setUrl(new Url('<none>'));
        }
        array_push($links, $current);
      }
    }

    return $breadcrumb->setLinks($links);
  }

  /**
   * If the current page is missing from the breadcrumb links, add it.
   *
   * @param \Drupal\Core\Link[] $links
   *   The breadcrumb links.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  protected function addMissingCurrentPage(array &$links, RouteMatchInterface $route_match) {
    // Check if the current page is already present.
    if (!empty($links)) {
      $last_url = end($links)->getUrl();
      if ($last_url->isRouted() &&
        $last_url->getRouteName() === $route_match->getRouteName() &&
        $last_url->getRouteParameters() === $route_match->getRawParameters()->all()
      ) {
        // We already have a link, no need to add one.
        return;
      }
    }

    // If we got here, the current page is missing from the breadcrumb links.
    // This can happen if the active trail is only partial, and doesn't reach
    // the current page, or if a taxonomy attachment is used.
    $title = $this->titleResolver->getTitle($this->currentRequest,
      $route_match->getRouteObject());
    if (isset($title)) {
      $links[] = Link::fromTextAndUrl($title,
        Url::fromRouteMatch($route_match));
    }
  }

}
