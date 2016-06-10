
MENU BREADCRUMBS
================

Introduction
------------
By default, Drupal 6 will use the Navigation menu for the breadcrumb. This module allows you to use the menu the current page belongs to for the breadcrumb.

As an added bonus, it also allows you to append the page title to the breadcrumb (either as a clickable url or not) and hide the breadcrumb if it only contains the link to the front page.

Installation
------------
1. Copy the menu_breadcrumb folder to your modules/contrib directory.
2. At Administer -> Extend (admin/modules) enable the module.
3. Configure the module settings at Administer -> Configuration -> User Interface (admin/config/user-interface/menu-breadcrumb).

Upgrading
---------
Replace the older menu_breadcrumb folder with the newer version, and then run update.php in case there are any database updates to apply.

Features
--------
- Allows you to use the menu the node belongs to for the breadcrumb on node pages.
- Append the page title to the breadcrumb.
- Optionally have the appended page title be an URL.
- Remove the breadcrumb if it only contains Homepage link.

Issues / Feature requests
-------------------------
If you find a bug, or have a feature request, please go to :

http://drupal.org/project/issues/menu_breadcrumb
