<?php

declare(strict_types=1);

use Auth0\Symfony\Security\UserProvider;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Config\SecurityConfig;

return static function (SecurityConfig $securityConfig): void {
    $securityConfig
        ->provider('auth0_provider')
        ->id(UserProvider::class);

    $securityConfig->firewall('dev')
        ->pattern('^/(_(profiler|wdt)|css|images|js)/')
        ->security(false);

    $securityConfig->firewall('stateless')
        ->pattern('^(/-/health-check|/media/cache|/sitemap)')
        ->stateless(true)
        ->security(false);

    $securityConfig->firewall('main')
        ->pattern('^/')
        ->provider('auth0_provider')
        ->customAuthenticators(['auth0.authenticator'])
        ->logout()
            ->path('app_logout')
            ->target('/');

    $securityConfig->accessControl()
        ->path('^/(muj-profil|upravit-profil|upravit-kod-hrace|pridat-cas|puzzle-stopky|zapnout-stopky|stopky|upravit-cas|smazat-cas|ulozit-stopky|porovnat-s-puzzlerem|pridat-hrace-k-oblibenym|odebrat-hrace-z-oblibenych|feedback|notifikace|competition-connect|cas-pridan|clenstvi|koupit-clenstvi)|(en/(save-stopwatch|add-time|compare-with-puzzler|delete-time|edit-profile|edit-player-code|edit-time|my-profile|stopwatch|start-stopwatch|puzzle-stopwatch|add-player-to-favorites|remove-player-from-favorites|feedback|notifications|competition-connect|time-added|membership|buy-membership))|(es/(guardar-cronometro|anadir-tiempo|comparar-con-puzzlista|eliminar-tiempo|editar-perfil|editar-codigo-jugador|editar-tiempo|mi-perfil|cronometro|iniciar-cronometro|cronometro-puzzle|anadir-jugador-a-favoritos|eliminar-jugador-de-favoritos|comentarios|notificaciones|conectar-competencia|tiempo-anadido|membresia|comprar-membresia))|(ja/(ストップウォッチ保存|時間追加|プレイヤー比較|時間削除|プロフィール編集|プレイヤーコード編集|時間編集|プロフィール|ストップウォッチ|ストップウォッチ開始|パズル-ストップウォッチ|お気に入り追加|お気に入り削除|フィードバック|通知|競技会接続|時間追加済み|メンバーシップ|メンバーシップ購入))|(fr/(sauvegarder-chronometre|ajouter-temps|comparer-avec-puzzleur|supprimer-temps|modifier-profil|modifier-code-joueur|modifier-temps|mon-profil|chronometre|demarrer-chronometre|chronometre-puzzle|ajouter-joueur-favoris|retirer-joueur-favoris|commentaires|notifications|connecter-competition|temps-ajoute|adhesion|acheter-adhesion))|(de/(stoppuhr-speichern|zeit-hinzufuegen|mit-puzzler-vergleichen|zeit-loeschen|profil-bearbeiten|spielercode-bearbeiten|zeit-bearbeiten|mein-profil|stoppuhr|stoppuhr-starten|puzzle-stoppuhr|spieler-zu-favoriten|spieler-aus-favoriten|feedback|benachrichtigungen|wettbewerb-verbinden|zeit-hinzugefuegt|mitgliedschaft|mitgliedschaft-kaufen))')
        ->roles([AuthenticatedVoter::IS_AUTHENTICATED_FULLY]);

    $securityConfig->accessControl()
        ->path('^/admin')
        ->roles([AuthenticatedVoter::IS_AUTHENTICATED_FULLY]);

    $securityConfig->accessControl()
        ->path('^/')
        ->roles([AuthenticatedVoter::PUBLIC_ACCESS]);
};
