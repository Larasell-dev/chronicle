<?php

namespace Larasell\Chronicle\Tests\Fixtures;

use Exception;
use Larasell\Chronicle\Exceptions\HasStructuredException;
use Larasell\Chronicle\Exceptions\StructuredException;

class CouldNotInviteMember extends Exception implements StructuredException
{
    use HasStructuredException;

    public static function emailAlreadyInvited(string $email, string $team): self
    {
        return self::structured(
            code: 'teams.could-not-invite-member.email-already-invited',
            message: "Could not invite {$email} to team {$team}: they already have a pending invitation.",
            why: "A pending invitation for {$email} already exists in team {$team}",
            fix: 'Resend the existing invitation or remove it first',
        );
    }
}
