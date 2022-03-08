<?php

namespace App\Entity;

use App\Repository\DataEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DataEntityRepository::class)]
class DataEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $name;

    #[ORM\OneToMany(mappedBy: 'dataEntity', targetEntity: AttributeEntity::class)]
    private $attributes;

    public function __construct()
    {
        $this->attributes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection<int, AttributeEntity>
     */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    public function addAttribute(AttributeEntity $attribute): self
    {
        if (!$this->attributes->contains($attribute)) {
            $this->attributes[] = $attribute;
            $attribute->setDataEntity($this);
        }

        return $this;
    }

    public function removeAttribute(AttributeEntity $attribute): self
    {
        if ($this->attributes->removeElement($attribute)) {
            // set the owning side to null (unless already changed)
            if ($attribute->getDataEntity() === $this) {
                $attribute->setDataEntity(null);
            }
        }

        return $this;
    }
}
