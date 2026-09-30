<?php

require_once __DIR__ . '/../helpers/ui.php';
require_once __DIR__ . '/../middleware/permiso.php';

?>

<aside class="sidebar">

    <div class="sidebar-content">

        <div class="sidebar-logo">

            <img
                src="<?= BASE_URL . ($config['ruta_logo'] ?? '/assets/img/logo.png') ?>"
                alt="<?= htmlspecialchars($config['nombre'] ?? 'Logo') ?>"
            >

        </div>

        <nav class="sidebar-nav">

        <?php if (tienePermiso('ver_dashboard')): ?>

        <a
            class="<?= menuActivo('/dashboard.php') ?>"
            href="<?= BASE_URL ?>/views/dashboard.php"
            title="Dashboard"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-house"></i></span>

            <span class="sidebar-nav__label">Dashboard</span>

        </a>

        <?php endif; ?>


        <?php if (tienePermiso('gestionar_jovenes')): ?>

        <a
            class="<?= menuActivo('/jovenes/') ?>"
            href="<?= BASE_URL ?>/views/jovenes/index.php"
            title="Jóvenes"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-users"></i></span>

            <span class="sidebar-nav__label">Jóvenes</span>

        </a>

        <?php endif; ?>


        <?php if (tienePermiso('gestionar_reuniones')): ?>

        <a
            class="<?= menuActivo('/reuniones/') ?>"
            href="<?= BASE_URL ?>/views/reuniones/index.php"
            title="Reuniones"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-calendar"></i></span>

            <span class="sidebar-nav__label">Reuniones</span>

        </a>

        <?php endif; ?>


        <?php if (tienePermiso('gestionar_reuniones') || tienePermiso('gestionar_formacion')): ?>

        <a
            class="<?= menuActivo('/formacion/') ?>"
            href="<?= BASE_URL ?>/views/formacion/index.php"
            title="Formación"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-graduation-cap"></i></span>

            <span class="sidebar-nav__label">Formación</span>

        </a>

        <?php endif; ?>


        <?php if (tienePermiso('ver_dashboard')): ?>

        <a
            class="<?= menuActivo('/reportes/') ?>"
            href="<?= BASE_URL ?>/views/reportes/index.php"
            title="Reportes"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-file-lines"></i></span>

            <span class="sidebar-nav__label">Reportes</span>

        </a>

        <?php endif; ?>


        <?php if (tienePermiso('gestionar_seguimientos')): ?>

        <a
            class="<?= menuActivo('/seguimientos/') ?>"
            href="<?= BASE_URL ?>/views/seguimientos/index.php"
            title="Seguimientos"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-notes-medical"></i></span>

            <span class="sidebar-nav__label">Seguimientos</span>

        </a>

        <?php endif; ?>


        <?php if (tienePermiso('gestionar_usuarios')): ?>

        <a
            class="<?= menuActivo('/usuarios/') ?>"
            href="<?= BASE_URL ?>/views/usuarios/index.php"
            title="Usuarios"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-users-gear"></i></span>

            <span class="sidebar-nav__label">Usuarios</span>

        </a>

        <?php endif; ?>


        <?php if (
            tienePermiso('gestionar_roles')
            || esAdmin()
        ): ?>

        <a
            class="<?= menuActivo('/roles/') ?>"
            href="<?= BASE_URL ?>/views/roles/index.php"
            title="Roles"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-gear"></i></span>

            <span class="sidebar-nav__label">Roles</span>

        </a>

        <?php endif; ?>

        </nav>

        <a
            class="sidebar-logout"
            href="<?= BASE_URL ?>/logout.php"
            title="Salir"
        >

            <span class="sidebar-nav__icon"><i class="fa-solid fa-right-from-bracket"></i></span>

            <span class="sidebar-nav__label">Salir</span>

        </a>

    </div>

</aside>