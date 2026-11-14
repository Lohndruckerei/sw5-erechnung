<?php

namespace LdZugferd;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;

class LdZugferd extends Plugin
{
    public function install(InstallContext $context)
    {
        $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
    }

    public function update(UpdateContext $context)
    {
        $context->scheduleClearCache(UpdateContext::CACHE_LIST_ALL);
    }

    public function activate(ActivateContext $context)
    {
        // Hook-Proxies müssen neu generiert werden, damit der render()-Hook greift.
        $context->scheduleClearCache(ActivateContext::CACHE_LIST_ALL);
    }

    public function deactivate(DeactivateContext $context)
    {
        $context->scheduleClearCache(DeactivateContext::CACHE_LIST_ALL);
    }

    public function uninstall(UninstallContext $context)
    {
        $context->scheduleClearCache(UninstallContext::CACHE_LIST_ALL);
    }
}
