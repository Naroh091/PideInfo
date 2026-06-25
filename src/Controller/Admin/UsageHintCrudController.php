<?php

namespace App\Controller\Admin;

use App\Entity\UsageHint;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UsageHintCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UsageHint::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Novedad')
            ->setEntityLabelInPlural('Novedades')
            ->setSearchFields(['title', 'content'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Novedades mostradas en el bloque descartable del panel de los usuarios (entre las estadísticas y el resumen).');
    }

    /**
     * Carga el editor de texto enriquecido Trix (mismo CDN que la redacción de
     * reclamaciones) y un pequeño script que convierte la textarea del campo
     * "Contenido" en un <trix-editor>. La textarea sigue siendo el campo real
     * del formulario (conserva su `name`); Trix solo escribe el HTML de vuelta
     * en ella en cada cambio, sin tocar el manejo de formularios de EasyAdmin.
     */
    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addHtmlContentToHead(
                '<link rel="stylesheet" href="https://unpkg.com/trix@2.1.15/dist/trix.css">'
            )
            ->addHtmlContentToBody(
                '<script src="https://unpkg.com/trix@2.1.15/dist/trix.umd.min.js"></script>'
                .'<script>'
                .'document.addEventListener("DOMContentLoaded", function () {'
                .'  document.querySelectorAll("textarea[data-trix]").forEach(function (ta) {'
                .'    if (ta.dataset.trixReady) return;'
                .'    ta.dataset.trixReady = "1";'
                .'    ta.style.display = "none";'
                .'    ta.removeAttribute("required");'
                .'    var input = document.createElement("input");'
                .'    input.type = "hidden";'
                .'    input.id = "trix_" + (ta.id || Math.random().toString(36).slice(2));'
                .'    input.value = ta.value;'
                .'    var editor = document.createElement("trix-editor");'
                .'    editor.setAttribute("input", input.id);'
                .'    editor.classList.add("trix-content");'
                .'    ta.after(input);'
                .'    input.after(editor);'
                .'    editor.addEventListener("trix-change", function () { ta.value = input.value; });'
                .'  });'
                .'});'
                .'</script>'
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Título');
        yield TextareaField::new('content', 'Contenido')
            ->setHelp('Editor de texto enriquecido. Usa negrita, cursiva, listas y enlaces.')
            ->setFormTypeOption('attr', ['data-trix' => 'true'])
            ->hideOnIndex();
        yield TextField::new('linkUrl', 'Enlace (URL o ruta)')
            ->setHelp('Opcional. Ej: /guias/documentos')
            ->hideOnIndex()
            ->setRequired(false);
        yield TextField::new('linkLabel', 'Texto del enlace')
            ->hideOnIndex()
            ->setRequired(false);
        yield BooleanField::new('isActive', 'Activa');
        yield DateTimeField::new('hideAt', 'Ocultar a partir de')
            ->setHelp('Opcional. Al llegar esta fecha, el comando diario desactiva la novedad. Vacío = no caduca.')
            ->setRequired(false);
        yield DateTimeField::new('createdAt', 'Creada')->hideOnForm();
    }
}
