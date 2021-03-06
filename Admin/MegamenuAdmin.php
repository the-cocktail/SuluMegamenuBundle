<?php

/**
 * This file is part of Sulu Megamenu Bundle.
 *
 * (c) The Cocktail Experience S.L.
 *
 *  This source file is subject to the MIT license that is bundled
 *  with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace TheCocktail\Bundle\MegaMenuBundle\Admin;

use TheCocktail\Bundle\MegaMenuBundle\Entity\MenuItem;
use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ResourceTabViewBuilder;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * @author Pablo Lozano <lozanomunarriz@gmail.com>
 */
class MegamenuAdmin extends Admin
{
    const SECURITY_CONTEXT = 'sulu.settings.megamenu';

    const NAVIGATION_TAB_VIEW = 'sulu_megamenu.navigation_tab_view';
    const NAVIGATION_FORM_KEY = 'megamenu_details';

    private ViewBuilderFactoryInterface $viewBuilderFactory;
    private WebspaceManagerInterface $webspaceManager;
    private SecurityCheckerInterface $securityChecker;
    private array $megamenus;

    public function __construct(
        ViewBuilderFactoryInterface $viewBuilderFactory,
        WebspaceManagerInterface $webspaceManager,
        SecurityCheckerInterface $securityChecker,
        array $megamenus
    ) {
        $this->viewBuilderFactory = $viewBuilderFactory;
        $this->webspaceManager = $webspaceManager;
        $this->securityChecker = $securityChecker;
        $this->megamenus = $megamenus;
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $navigation = new NavigationItem('sulu_megamenu.navigation.title');
            $navigation->setPosition(40);
            $navigation->setIcon('fa-bars');
            $navigation->setView(self::NAVIGATION_TAB_VIEW);

            $navigationItemCollection->add($navigation);
        }
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        $locales = $this->webspaceManager->getAllLocales();

        $listToolbarActions = [];
        $formToolbarActionsWithType = [];

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::ADD)) {
            $listToolbarActions[] = new ToolbarAction('sulu_admin.add');
        }

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::EDIT)) {
            $formToolbarActionsWithType[] = new ToolbarAction('sulu_admin.save');
        }

        if ($this->securityChecker->hasPermission(static::SECURITY_CONTEXT, PermissionTypes::DELETE)) {
            $formToolbarActionsWithType[] = new ToolbarAction('sulu_admin.delete');
            $listToolbarActions[] = new ToolbarAction('sulu_admin.delete');
        }

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createViewBuilder(self::NAVIGATION_TAB_VIEW, '/navigation', ResourceTabViewBuilder::TYPE)
                ->setOption('resourceKey', MenuItem::RESOURCE_KEY)
        );

        $webspaceCollection = $this->webspaceManager->getWebspaceCollection();

        foreach ($webspaceCollection->getWebspaces() as $webspace) {
            $navigationListView = 'sulu_megamenu.navigation_list_' . $webspace->getKey();
            $navigationAddView = 'sulu_megamenu.navigation_add_' . $webspace->getKey();
            $navigationEditView = 'sulu_megamenu.navigation_edit_' . $webspace->getKey();

            $listView = $this->viewBuilderFactory->createListViewBuilder($navigationListView, '/'. $webspace->getKey(). '/:locale')
                ->setResourceKey(MenuItem::RESOURCE_KEY)
                ->setListKey(MenuItem::RESOURCE_KEY)
                ->setTitle($webspace->getName())
                ->setTabTitle($webspace->getName())
                ->addListAdapters(['tree_table'])
                ->addLocales($locales)
                ->addRequestParameters(['webspace' => $webspace->getKey()])
                ->disableSearching()
                ->setDefaultLocale($locales[0])
                ->setAddView($navigationAddView)
                ->setEditView($navigationEditView)
                ->addToolbarActions($listToolbarActions)
                ->setParent(self::NAVIGATION_TAB_VIEW);
            $viewCollection->add($listView);

            // Configure Menu Add View
            $addFormView = $this->viewBuilderFactory->createResourceTabViewBuilder($navigationAddView, '/navigation/add')
                ->setResourceKey(MenuItem::RESOURCE_KEY)
                ->setBackView($navigationListView)
                ->setAttributeDefault('webspace', $webspace->getKey())
                ->addRerenderAttribute('parentId')
            ;
            $viewCollection->add($addFormView);

            $addDetailsFormView = $this->viewBuilderFactory->createFormViewBuilder($navigationAddView . '.details', '/details')
                ->setResourceKey(MenuItem::RESOURCE_KEY)
                ->setFormKey(self::NAVIGATION_FORM_KEY)
                ->setTabTitle('sulu_admin.details')
                ->setEditView($navigationEditView)
                ->addToolbarActions($formToolbarActionsWithType)
                ->addRouterAttributesToFormRequest(['locale', 'resourceKey', 'webspace', 'parentId'])
                ->setParent($navigationAddView);
            $viewCollection->add($addDetailsFormView);

            // Configure Menu Edit View
            $editFormView = $this->viewBuilderFactory->createResourceTabViewBuilder($navigationEditView, '/navigation/:id')
                ->setResourceKey(MenuItem::RESOURCE_KEY)
                ->setBackView(self::NAVIGATION_TAB_VIEW)
                ->setTitleProperty('title')
                ->setAttributeDefault('webspace', $webspace->getKey())
            ;
            $viewCollection->add($editFormView);

            $editDetailsFormView = $this->viewBuilderFactory->createFormViewBuilder($navigationEditView . '.details', '/details')
                ->setResourceKey(MenuItem::RESOURCE_KEY)
                ->setFormKey(self::NAVIGATION_FORM_KEY)
                ->setTabTitle('sulu_admin.details')
                ->addToolbarActions($formToolbarActionsWithType)
                ->addRouterAttributesToFormRequest(['locale', 'resourceKey', 'webspace', 'parentId'])
                ->setParent($navigationEditView);
            $viewCollection->add($editDetailsFormView);
        }
    }

    public function getSecurityContexts()
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'Settings' => [
                    self::SECURITY_CONTEXT => [
                        PermissionTypes::VIEW,
                        PermissionTypes::ADD,
                        PermissionTypes::EDIT,
                        PermissionTypes::DELETE,
                    ],
                ],
            ],
        ];
    }
}
