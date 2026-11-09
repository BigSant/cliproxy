<?php

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A dashboard setting or collector mark (Settings knows the names), kept as JSON. */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
class Setting
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        public readonly string $name,
        #[ORM\Column(type: Types::JSON)]
        public mixed $value,
    ) {
    }
}
