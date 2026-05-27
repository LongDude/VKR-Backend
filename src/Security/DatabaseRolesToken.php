<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class DatabaseRolesToken extends PostAuthenticationToken
{
    /**
     * @return list<string>
     */
    public function getRoleNames(): array
    {
        $user = $this->getUser();

        if ($user instanceof UserInterface) {
            return array_values($user->getRoles());
        }

        return parent::getRoleNames();
    }
}
