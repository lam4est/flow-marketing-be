<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\ContactList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * @return list<Contact>
     */
    public function findByContactList(ContactList $list): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.contactList = :list')
            ->setParameter('list', $list)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
