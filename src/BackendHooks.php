<?php
/**
 * Copyright (c) 2019 leycom - media solutions
 */
namespace leycommediasolutions\FrontendBuilder;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Database;
use Contao\Input;
use Symfony\Component\HttpFoundation\RequestStack;

class BackendHooks
{
    private RequestStack $requestStack;
    private ScopeMatcher $scopeMatcher;

    public function __construct(RequestStack $requestStack, ScopeMatcher $scopeMatcher)
    {
        $this->requestStack = $requestStack;
        $this->scopeMatcher = $scopeMatcher;
    }
    public function isBackend(): bool
    {
        return $this->scopeMatcher->isBackendRequest($this->requestStack->getCurrentRequest());
    }

    public function isFrontend(): bool
    {
        return $this->scopeMatcher->isFrontendRequest($this->requestStack->getCurrentRequest());
    }
    public function myGetAttributesFromDca(array $attributes, $objDca): array
    {
        if (!$this->isBackend()) {
		    return $attributes;
        }
        $type = Input::get('selectboxvalue');
        if($attributes['name'] === 'type' && $type !== ''){

            Database::getInstance()
			->prepare("UPDATE tl_content SET type=?  WHERE id=?")
            ->execute($type , $objDca->id);
        }
        return $attributes;
    }
}
