<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%mail_from_address%')] private string $mailFromAddress,
        #[Autowire('%mail_from_name%')] private string $mailFromName,
        #[Autowire(env: 'bool:USER_NEEDS_MANUAL_ACTIVATION')] private bool $needsManualActivation,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Usuario')
            ->setEntityLabelInPlural('Usuarios')
            ->setSearchFields(['email', 'firstName', 'lastName'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email', 'Email');
        yield TextField::new('firstName', 'Nombre');
        yield TextField::new('lastName', 'Apellidos');
        yield AssociationField::new('organization', 'Organización');
        yield ArrayField::new('roles', 'Roles');
        yield BooleanField::new('isVerified', 'Verificado');
        yield BooleanField::new('isActive', 'Activo');
        yield DateTimeField::new('createdAt', 'Registrado')->hideOnForm();
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $sendActivationEmail = false;

        if ($entityInstance instanceof User && $this->needsManualActivation) {
            $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
            $wasActive = $originalData['isActive'] ?? false;

            if (!$wasActive && $entityInstance->isActive()) {
                $sendActivationEmail = true;
            }
        }

        parent::updateEntity($entityManager, $entityInstance);

        if ($sendActivationEmail) {
            $email = (new TemplatedEmail())
                ->from(new Address($this->mailFromAddress, $this->mailFromName))
                ->to($entityInstance->getEmail())
                ->subject('Tu cuenta en PideInfo ha sido activada')
                ->htmlTemplate('email/account_activated.html.twig')
                ->context(['user' => $entityInstance]);

            $this->mailer->send($email);
        }
    }
}
