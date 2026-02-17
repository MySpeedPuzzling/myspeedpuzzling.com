#!/usr/bin/env python3
"""
One-time script to add missing translations to locale YAML files.
Handles both emails.{locale}.yml (append) and messages.{locale}.yml (deep merge).
"""

import yaml
import copy
import sys
import os

TRANSLATIONS_DIR = os.path.join(os.path.dirname(__file__), '..', 'translations')

# =============================================================================
# EMAIL TRANSLATIONS (unread_messages section)
# =============================================================================

EMAIL_TRANSLATIONS = {
    'cs': {
        'unread_messages': {
            'subject': 'Mate neprectenezpravy na MySpeedPuzzling',
            'title': 'Mate neprectene zpravy',
            'greeting': 'Ahoj %playerName%,',
            'intro': 'Cekaji na vas neprectene zpravy:',
            'message_line': '%count% zprav(a/y) od %senderName%',
            'message_line_with_puzzle': '%count% zprav(a/y) od %senderName% (ohledne: %puzzleName%)',
            'view_messages': 'Zobrazit zpravy',
            'pending_requests_also': 'Take mate %count% novych zadosti o zpravu.',
            'pending_requests_only': 'Mate %count% novych zadosti o zpravu.',
            'view_requests': 'Zobrazit zadosti o zpravu',
            'opt_out': 'Tato upozorneni muzete vypnout v <a href="%settingsUrl%">nastaveni profilu</a>.',
        },
    },
    'de': {
        'unread_messages': {
            'subject': 'Du hast ungelesene Nachrichten auf MySpeedPuzzling',
            'title': 'Du hast ungelesene Nachrichten',
            'greeting': 'Hallo %playerName%,',
            'intro': 'Du hast ungelesene Nachrichten:',
            'message_line': '%count% Nachricht(en) von %senderName%',
            'message_line_with_puzzle': '%count% Nachricht(en) von %senderName% (zu: %puzzleName%)',
            'view_messages': 'Nachrichten anzeigen',
            'pending_requests_also': 'Du hast auch %count% neue Nachrichtenanfrage(n).',
            'pending_requests_only': 'Du hast %count% neue Nachrichtenanfrage(n).',
            'view_requests': 'Nachrichtenanfragen anzeigen',
            'opt_out': 'Du kannst diese Benachrichtigungen in deinen <a href="%settingsUrl%">Profileinstellungen</a> deaktivieren.',
        },
    },
    'es': {
        'unread_messages': {
            'subject': 'Tienes mensajes sin leer en MySpeedPuzzling',
            'title': 'Tienes mensajes sin leer',
            'greeting': 'Hola %playerName%,',
            'intro': 'Tienes mensajes sin leer esperandote:',
            'message_line': '%count% mensaje(s) de %senderName%',
            'message_line_with_puzzle': '%count% mensaje(s) de %senderName% (sobre: %puzzleName%)',
            'view_messages': 'Ver tus mensajes',
            'pending_requests_also': 'Tambien tienes %count% nueva(s) solicitud(es) de mensaje.',
            'pending_requests_only': 'Tienes %count% nueva(s) solicitud(es) de mensaje.',
            'view_requests': 'Ver solicitudes de mensaje',
            'opt_out': 'Puedes desactivar estas notificaciones en la <a href="%settingsUrl%">configuracion de tu perfil</a>.',
        },
    },
    'fr': {
        'unread_messages': {
            'subject': 'Vous avez des messages non lus sur MySpeedPuzzling',
            'title': 'Vous avez des messages non lus',
            'greeting': 'Bonjour %playerName%,',
            'intro': 'Vous avez des messages non lus qui vous attendent :',
            'message_line': '%count% message(s) de %senderName%',
            'message_line_with_puzzle': '%count% message(s) de %senderName% (a propos de : %puzzleName%)',
            'view_messages': 'Voir vos messages',
            'pending_requests_also': 'Vous avez egalement %count% nouvelle(s) demande(s) de message.',
            'pending_requests_only': 'Vous avez %count% nouvelle(s) demande(s) de message.',
            'view_requests': 'Voir les demandes de message',
            'opt_out': 'Vous pouvez desactiver ces notifications dans les <a href="%settingsUrl%">parametres de votre profil</a>.',
        },
    },
    'ja': {
        'unread_messages': {
            'subject': 'MySpeedPuzzlingで未読メッセージがあります',
            'title': '未読メッセージがあります',
            'greeting': '%playerName%さん、こんにちは',
            'intro': '未読メッセージがあります：',
            'message_line': '%senderName%からの%count%件のメッセージ',
            'message_line_with_puzzle': '%senderName%からの%count%件のメッセージ（%puzzleName%について）',
            'view_messages': 'メッセージを見る',
            'pending_requests_also': '%count%件の新しいメッセージリクエストもあります。',
            'pending_requests_only': '%count%件の新しいメッセージリクエストがあります。',
            'view_requests': 'メッセージリクエストを見る',
            'opt_out': 'これらの通知は<a href="%settingsUrl%">プロフィール設定</a>でオフにできます。',
        },
    },
}

# =============================================================================
# MESSAGES TRANSLATIONS
# =============================================================================

MESSAGES_TRANSLATIONS = {
    'cs': {
        # Root-level keys
        'back_to': 'Zpet na',
        'edit_my_puzzle_offer': 'Upravit mou nabidku puzzle',

        # edit_profile additions
        'edit_profile': {
            'messaging_notifications': 'Zpravy a oznameni',
            'allow_direct_messages': 'Povolit ostatnim uzivatelum posilat mi prime zpravy',
            'email_notifications': 'Zasilat e-mailova oznameni o neprectených zpravach',
        },

        # membership additions
        'membership': {
            'have_voucher_code': 'Mate kod voucheru?',
            'redeem_voucher': 'Uplatnit voucher',
        },

        # puzzler_offers additions
        'puzzler_offers': {
            'column': {
                'comment': 'Komentar',
            },
            'offers_link': 'Nabidky (%count%)',
            'view_all': 'Zobrazit vsech %count% nabidek',
            'view_on_marketplace': 'Zobrazit na marketplace',
        },

        # sell_swap_list additions
        'sell_swap_list': {
            'form': {
                'publish_on_marketplace': 'Zverejnit na Marketplace',
                'publish_on_marketplace_help': 'Pokud je povoleno, tato polozka se take objevi na verejnem marketplace.',
            },
            'on_marketplace': 'Na Marketplace',
            'reserved': 'REZERVOVANO',
            'reserved.success': 'Nabidka byla oznacena jako rezervovana.',
            'reservation_removed.success': 'Puzzle uz neni rezervovano a je opet dostupne.',
            'mark_reserved': {
                'button': 'Oznacit jako rezervovane',
                'title': 'Oznacit jako rezervovane',
                'confirm': 'Potvrdit rezervaci',
                'reserved_for_input': 'Rezervovano pro',
                'reserved_for_input_help': 'Zadejte kod hrace (napr. #ABC123) nebo jmeno. Nechte prazdne, pokud neni znamo.',
                'reserved_for_input_placeholder': '#ABC123 nebo jmeno...',
            },
            'remove_reservation': {
                'button': 'Zrusit rezervaci',
            },
            'settings': {
                'ships_to': 'Zasila do',
                'shipping_countries': 'Zeme, do kterych zasilate',
                'shipping_cost': 'Informace o cene dopravy',
                'shipping_cost_placeholder': 'napr. Doprava zdarma nad 50 EUR, pausalni sazba 5 EUR',
                'region': {
                    'central_europe': 'Stredni Evropa',
                    'western_europe': 'Zapadni Evropa',
                    'southern_europe': 'Jizni Evropa',
                    'northern_europe': 'Severni Evropa',
                    'eastern_europe': 'Vychodni Evropa',
                    'north_america': 'Severni Amerika',
                    'rest_of_world': 'Zbytek sveta',
                },
            },
            'settings_alert': {
                'title': 'Uz to skoro je!',
                'subtitle': 'Dokoncete prosim tyto kroky, aby vase puzzle mohla byt nalezena na Marketplace.',
                'missing_currency': 'Nastavte svou menu',
                'missing_shipping_countries': 'Pridejte zeme pro zasilani',
                'cta': 'Dokoncit nastaveni',
            },
        },

        # messaging (new section)
        'messaging': {
            'request_accepted': 'Zadost o zpravu byla prijata.',
            'request_ignored': 'Zadost o zpravu byla ignorovana.',
            'request_sent': 'Zadost o zpravu byla uspesne odeslana.',
            'message_empty': 'Zprava nemuze byt prazdna.',
            'user_blocked': 'Uzivatel byl zablokovan.',
            'user_unblocked': 'Uzivatel byl odblokovan.',
            'cannot_message_user': 'Tomuto uzivateli nemuzete poslat zpravu.',
            'direct_messages_disabled': 'Tento uzivatel neprijima prime zpravy.',
            'pending_request_exists': 'S timto uzivatelem uz mate cekajici zadost o zpravu.',
            'cannot_contact_yourself': 'Nemuzete kontaktovat sami sebe.',
            'conversations': 'Konverzace',
            'requests': 'Zadosti',
            'title': 'Zpravy',
            'send': 'Odeslat',
            'send_placeholder': 'Napiste zpravu...',
            'block_user': 'Zablokovat uzivatele',
            'block_confirm': 'Opravdu chcete zablokovat tohoto uzivatele?',
            'report': 'Nahlasit',
            'report_reason_placeholder': 'Popiste, proc nahlasujete tuto konverzaci...',
            'report_button': 'Odeslat nahlaseni',
            'accept': 'Prijmout',
            'ignore': 'Ignorovat',
            'new_message': 'Nova zprava',
            'no_conversations': 'Zatim nemate zadne konverzace.',
            'no_requests': 'Nemate zadne cekajici zadosti.',
            'pending_status': 'Ceka na schvaleni',
            'pending_notice': 'Tato zadost o konverzaci ceka na schvaleni.',
            'ignored': 'Ignorovano',
            'ignored_status': 'Ignorovano',
            'ignored_notice': 'Tato zadost o konverzaci byla ignorovana.',
            'no_conversations_yet': 'Zatim zadne konverzace.',
            'no_pending_requests': 'Zadne cekajici zadosti o zpravu.',
            'no_ignored_conversations': 'Zadne ignorovane konverzace.',
            'back_to_messages': 'Zpravy',
            'interested_in_puzzle': '%name% ma zajem o vase puzzle',
            'wants_to_message': '%name% vam chce napsat',
            'pending_request_notice': 'Toto je zadost o zpravu.',
            'accept_disclaimer': 'Kdyz prijmete, odesilatel uvidi, ze jste aktivni a prectli zpravy.',
            'waiting_for_acceptance': 'Cekani na prijeti vasi zadosti o zpravu od %name%.',
            'view': 'Zobrazit',
            'no_messages_yet': 'Zatim zadne zpravy. Zacnete konverzaci!',
            'type_message': 'Napiste zpravu...',
            'message_label': 'Zprava',
            'write_message_placeholder': 'Napiste svou zpravu...',
            'max_characters': 'Maximalne 2000 znaku.',
            'send_message': 'Odeslat zpravu',
            'send_failed': 'Odeslani zpravy se nezdarilo. Zkuste to prosim znovu.',
            'conversation_with': 'Konverzace s %name%',
            'blocked_users': 'Zablokovani uzivatele',
            'no_blocked_users': 'Nezablokovali jste zadne uzivatele.',
            'blocked_on': 'Zablokovano dne',
            'unblock_confirm': 'Opravdu chcete odblokovat tohoto uzivatele?',
            'unblock': 'Odblokovat',
            'report_conversation': 'Nahlasit konverzaci',
            'report_confirm': 'Opravdu chcete nahlasit tuto konverzaci?',
            'report_reason': 'Duvod',
            'cancel': 'Zrusit',
            'report_submit': 'Odeslat nahlaseni',
            'you_prefix': 'Vy:',
            'read_status': 'Precteno',
            'sent_status': 'Odeslano',
            'typing': 'pise...',
            'actions': {
                'to_this_puzzler': 'Tomuto puzzlerovi',
                'to_someone_else': 'Nekomu jinemu',
                'reserved': 'Rezervovano',
                'sold_swapped': 'Prodano/vymeneno',
            },
            'conversation_partners': '- Z konverzaci na marketplace -',
            'system': {
                'listing_reserved_for_you': 'Toto puzzle bylo rezervovano pro vas.',
                'listing_reserved_for_this_puzzler': 'Toto puzzle bylo rezervovano pro tohoto puzzlera.',
                'listing_reserved_for_someone_else': 'Toto puzzle bylo rezervovano pro nekoho jineho.',
                'listing_reserved': 'Toto puzzle bylo rezervovano.',
                'listing_reservation_removed': 'Toto puzzle uz neni rezervovano a je opet dostupne.',
                'listing_sold_to_you': 'Toto puzzle bylo prodano/vymeneno vam.',
                'listing_sold_to_this_puzzler': 'Toto puzzle jste prodali/vymenili tomuto puzzlerovi.',
                'listing_sold_to_someone_else': 'Toto puzzle bylo prodano/vymeneno nekomu jinemu.',
                'listing_sold': 'Toto puzzle bylo prodano/vymeneno nekomu jinemu.',
                'preview': {
                    'listing_reserved': 'Puzzle bylo rezervovano',
                    'listing_reservation_removed': 'Puzzle je opet dostupne',
                    'listing_sold': 'Puzzle bylo prodano/vymeneno',
                },
            },
            'rate_transaction': 'Ohodnotit transakci',
            'your_rating': 'Vase hodnoceni',
            'rate_pending': 'Ohodnotit',
        },

        # rating (new section)
        'rating': {
            'title': 'Hodnoceni',
            'title_for_player': 'Hodnoceni pro %name%',
            'submit_button': 'Odeslat hodnoceni',
            'stars_label': 'Hodnoceni',
            'review_label': 'Recenze (nepovinne)',
            'review_placeholder': 'Podelte se o svou zkusenost...',
            'thank_you': 'Dekujeme za vase hodnoceni!',
            'cannot_rate': 'Tuto transakci nemuzete hodnotit. Mozna uz byla hodnocena nebo vyprsel cas pro hodnoceni.',
            'invalid_stars': 'Vyberte prosim hodnoceni mezi 1 a 5 hvezdami.',
            'already_rated': 'Tuto transakci jste jiz hodnotili.',
            'expired': 'Cas pro hodnoceni vyprsel (30 dni).',
            'not_allowed': 'Nemate opravneni hodnotit tuto transakci.',
            'no_ratings': 'Zatim zadna hodnoceni.',
            'rate_transaction': 'Ohodnotit tuto transakci',
            'average': 'Prumerne hodnoceni',
            'count': '%count% hodnoceni',
            'count_singular': '1 hodnoceni',
            'rate_your_transaction': 'Ohodnotte svou transakci',
            'sold': 'Prodano',
            'swapped': 'Vymeneno',
            'sold_to': 'Prodano',
            'swapped_with': 'Vymeneno s',
            'bought_from': 'Koupeno od',
            'back_to_profile': 'Zpet na profil',
            'cancel': 'Zrusit',
            'optional_note': 'Nepovinne. Max 500 znaku.',
            'role': {
                'seller': 'Prodejce',
                'buyer': 'Kupec',
            },
            'view_all': 'Zobrazit vse',
        },

        # marketplace (new section)
        'marketplace': {
            'title': 'Marketplace',
            'meta': {
                'description': 'Prohledejte puzzle k prodeji, vymene nebo zdarma na MySpeedPuzzling marketplace.',
            },
            'all_countries': 'Vsechny zeme',
            'pcs': 'ks',
            'filter': {
                'search_placeholder': 'Hledat puzzle...',
                'brand': 'Znacka',
                'pieces': 'Dilky',
                'type': 'Typ',
                'price': 'Cena',
                'condition': 'Stav',
                'sort': 'Radit',
                'filters': 'Filtry',
                'ships_to': 'Zasila do',
                'ship_to_my_country': 'Zasilani do me zeme',
                'ship_to_my_country_disabled': 'Zasilani do me zeme (nastavte svou zemi v',
                'set_country_link': 'Upravit profil)',
            },
            'sort': {
                'newest': 'Nejnovejsi',
                'price_asc': 'Cena vzestupne',
                'price_desc': 'Cena sestupne',
                'name_asc': 'Nazev A-Z',
                'name_desc': 'Nazev Z-A',
                'relevance': 'Relevance',
            },
            'listing_type': {
                'sell': 'Prodej',
                'swap': 'Vymena',
                'both': 'Prodej / Vymena',
                'free': 'Zdarma',
            },
            'condition': {
                'like_new': 'Jako nove',
                'normal': 'Normalni',
                'not_so_good': 'Neni v nejlepsim stavu',
                'missing_pieces': 'Chybejici dilky',
            },
            'results': 'Celkem %count% vysledku',
            'result': 'Celkem 1 vysledek',
            'no_results': 'Nebyly nalezeny zadne nabidky odpovidajici vasim filtrum.',
            'reserved': 'REZERVOVANO',
            'contact_seller': 'Kontaktovat prodejce',
            'price_not_disclosed': 'Cena nestanovena',
            'shipping_cost': 'Doprava',
            'all_types': 'Vsechny typy',
            'all_conditions': 'Vsechny stavy',
            'all_brands': 'Vsechny znacky',
            'min': 'Min',
            'max': 'Max',
            'search_placeholder': 'Nazev puzzle, EAN...',
            'all': 'Vse',
            'my_offers': 'Moje nabidky',
            'manage_my_offers': 'Spravovat moje nabidky',
            'showing_offers_for': 'Zobrazuji nabidky pro',
            'show_all': 'Zobrazit vse',
            'load_more': 'Nacist dalsi',
            'disclaimer': 'MySpeedPuzzling neni prodejce a nenese zodpovednost za transakce. Pouze propojujeme lidi. Budte vzdy opatrni a neposilejte penize predem.',
            'how_it_works_button': 'Jak to funguje?',
            'how_it_works': {
                'title': 'Jak Marketplace funguje',
                'back_to_marketplace': 'Marketplace',
                'browse_marketplace': 'Prohledejte Marketplace',
                'intro': 'MySpeedPuzzling Marketplace je misto, kde se milovnici puzzle propojuji, aby prodavali, vymenovali nebo darovavali puzzle. Zde je vse, co potrebujete vedet.',
                'step1': {
                    'title': 'Pridejte sve puzzle',
                    'description': 'Prejdete na jakekoli puzzle ve sve sbirce a pridejte ho na svuj seznam k prodeji/vymene. Muzete to udelat ze stranky detailu puzzle, puzzle knihovny nebo primo ze sbirky. Nezapomente zatrhnout "Zobrazit na Marketplace", aby vas ostatni puzzleri nasli.',
                },
                'step2': {
                    'title': 'Nastavte svuj profil',
                    'description': 'Pred pridanim vyplnte zeme, do kterych zasilate, preferovanou menu a volitelne naklady na dopravu a kontaktni udaje. To pomaha kupcum vedet, co mohou ocekavat.',
                },
                'step3': {
                    'title': 'Komunikujte s ostatnimi puzzlery',
                    'description': 'Pouzijte vestaveny chat pro komunikaci primo se zajemci primo zde na platforme. Muzete si take domluvit veci mimo platformu, pokud chcete - je to na vas.',
                },
                'step4': {
                    'title': 'Rezervujte puzzle',
                    'description': 'Kdyz se dohodnete na obchodu, oznacte puzzle jako rezervovane. To ostatnim puzzlerum da vedet, ze polozka je zabrana, dokud se transakce nedokonci.',
                },
                'step5': {
                    'title': 'Dokoncete transakci',
                    'description': 'Jakmile je puzzle prodano nebo vymeneno, oznacte ho jako dokoncene. Puzzle bude automaticky odebrano z vasi sbirky a kupec si ho muze pridat primo do sve.',
                },
                'step6': {
                    'title': 'Zanechte hodnoceni',
                    'description': 'Po dokonceni transakce nezapomente ohodnotit druheho puzzlera! Hodnoceni pomahaji budovat duveru v komunite a ukazuji ostatnim, jak transakce probehla.',
                },
                'community': {
                    'title': 'Budte pratelsti a bavte se!',
                    'description': 'Vsichni jsme tady, protoze milujeme puzzle. Puzzle maji prineset radost, a stejne tak tento marketplace. Budte mili, trpelivi a uzijte si propojeni s ostatnimi puzzle nadšenci z celeho sveta.',
                },
                'safety': {
                    'title': 'Budte v bezpeci',
                    'description': 'MySpeedPuzzling nenese zodpovednost za zadne transakce. Jsme zde, abychom propojili puzzlery, ale vsechny obchody jsou mezi vami a druhou stranou. Budte vzdy opatrni s peneznimi transakcemi a nikdy neposilejte platbu bez radneho dohovoru.',
                    'block_info': 'Pokud narazite na jakekoli neslusne chovani, muzete konverzaci zablokovat. Druha osoba se nikdy nedozvi, ze byla zablokovana.',
                },
            },
        },

        # moderation (new section)
        'moderation': {
            'report_submitted': 'Nahlaseni bylo uspesne odeslano. Nas tym ho posoudí.',
            'report_resolved': 'Nahlaseni bylo vyreseno.',
            'report_dismissed': 'Nahlaseni bylo zamitnuto.',
            'warning_issued': 'Varovani bylo vydano.',
            'user_muted': 'Uzivatel byl ztlumen na %days% dni.',
            'user_unmuted': 'Uzivatel byl odtlumen.',
            'marketplace_banned': 'Uzivatel byl zakazan na marketplace.',
            'ban_lifted': 'Zakaz na marketplace byl zrusen.',
            'listing_removed': 'Nabidka byla odebrana.',
        },
    },

    'de': {
        'back_to': 'Zuruck zu',
        'edit_my_puzzle_offer': 'Mein Puzzle-Angebot bearbeiten',

        'edit_profile': {
            'messaging_notifications': 'Nachrichten & Benachrichtigungen',
            'allow_direct_messages': 'Anderen Benutzern erlauben, mir direkte Nachrichten zu senden',
            'email_notifications': 'E-Mail-Benachrichtigungen uber ungelesene Nachrichten senden',
        },

        'membership': {
            'have_voucher_code': 'Haben Sie einen Gutscheincode?',
            'redeem_voucher': 'Gutschein einlosen',
        },

        'puzzler_offers': {
            'column': {
                'comment': 'Kommentar',
            },
            'offers_link': 'Angebote (%count%)',
            'view_all': 'Alle %count% Angebote anzeigen',
            'view_on_marketplace': 'Auf dem Marketplace anzeigen',
        },

        'sell_swap_list': {
            'form': {
                'publish_on_marketplace': 'Auf dem Marketplace veroffentlichen',
                'publish_on_marketplace_help': 'Wenn aktiviert, erscheint dieser Artikel auch auf dem offentlichen Marketplace.',
            },
            'on_marketplace': 'Auf dem Marketplace',
            'reserved': 'RESERVIERT',
            'reserved.success': 'Angebot wurde als reserviert markiert.',
            'reservation_removed.success': 'Das Puzzle ist nicht mehr reserviert und wieder verfugbar.',
            'mark_reserved': {
                'button': 'Als reserviert markieren',
                'title': 'Als reserviert markieren',
                'confirm': 'Reservierung bestatigen',
                'reserved_for_input': 'Reserviert fur',
                'reserved_for_input_help': 'Geben Sie den Spielercode (z.B. #ABC123) oder Namen ein. Leer lassen, wenn unbekannt.',
                'reserved_for_input_placeholder': '#ABC123 oder Name...',
            },
            'remove_reservation': {
                'button': 'Reservierung aufheben',
            },
            'settings': {
                'ships_to': 'Versendet nach',
                'shipping_countries': 'Lander, in die Sie versenden',
                'shipping_cost': 'Versandkosten-Info',
                'shipping_cost_placeholder': 'z.B. Kostenloser Versand ab 50 EUR, Pauschale 5 EUR',
                'region': {
                    'central_europe': 'Mitteleuropa',
                    'western_europe': 'Westeuropa',
                    'southern_europe': 'Sudeuropa',
                    'northern_europe': 'Nordeuropa',
                    'eastern_europe': 'Osteuropa',
                    'north_america': 'Nordamerika',
                    'rest_of_world': 'Rest der Welt',
                },
            },
            'settings_alert': {
                'title': 'Fast geschafft!',
                'subtitle': 'Bitte vervollstandigen Sie diese Schritte, damit Ihre Puzzle auf dem Marketplace gefunden werden konnen.',
                'missing_currency': 'Wahrung einstellen',
                'missing_shipping_countries': 'Versandlander hinzufugen',
                'cta': 'Einrichtung abschliessen',
            },
        },

        'messaging': {
            'request_accepted': 'Nachrichtenanfrage wurde akzeptiert.',
            'request_ignored': 'Nachrichtenanfrage wurde ignoriert.',
            'request_sent': 'Nachrichtenanfrage wurde erfolgreich gesendet.',
            'message_empty': 'Nachricht darf nicht leer sein.',
            'user_blocked': 'Benutzer wurde blockiert.',
            'user_unblocked': 'Benutzer wurde entsperrt.',
            'cannot_message_user': 'Sie konnen diesem Benutzer keine Nachricht senden.',
            'direct_messages_disabled': 'Dieser Benutzer akzeptiert keine Direktnachrichten.',
            'pending_request_exists': 'Sie haben bereits eine ausstehende Nachrichtenanfrage mit diesem Benutzer.',
            'cannot_contact_yourself': 'Sie konnen sich nicht selbst kontaktieren.',
            'conversations': 'Konversationen',
            'requests': 'Anfragen',
            'title': 'Nachrichten',
            'send': 'Senden',
            'send_placeholder': 'Nachricht eingeben...',
            'block_user': 'Benutzer blockieren',
            'block_confirm': 'Sind Sie sicher, dass Sie diesen Benutzer blockieren mochten?',
            'report': 'Melden',
            'report_reason_placeholder': 'Beschreiben Sie, warum Sie diese Konversation melden...',
            'report_button': 'Meldung absenden',
            'accept': 'Akzeptieren',
            'ignore': 'Ignorieren',
            'new_message': 'Neue Nachricht',
            'no_conversations': 'Sie haben noch keine Konversationen.',
            'no_requests': 'Sie haben keine ausstehenden Anfragen.',
            'pending_status': 'Ausstehend',
            'pending_notice': 'Diese Konversationsanfrage wartet auf Genehmigung.',
            'ignored': 'Ignoriert',
            'ignored_status': 'Ignoriert',
            'ignored_notice': 'Diese Konversationsanfrage wurde ignoriert.',
            'no_conversations_yet': 'Noch keine Konversationen.',
            'no_pending_requests': 'Keine ausstehenden Nachrichtenanfragen.',
            'no_ignored_conversations': 'Keine ignorierten Konversationen.',
            'back_to_messages': 'Nachrichten',
            'interested_in_puzzle': '%name% interessiert sich fur Ihr Puzzle',
            'wants_to_message': '%name% mochte Ihnen schreiben',
            'pending_request_notice': 'Dies ist eine Nachrichtenanfrage.',
            'accept_disclaimer': 'Wenn Sie akzeptieren, sieht der Absender, dass Sie aktiv sind und die Nachrichten gelesen haben.',
            'waiting_for_acceptance': 'Warten darauf, dass %name% Ihre Nachrichtenanfrage akzeptiert.',
            'view': 'Anzeigen',
            'no_messages_yet': 'Noch keine Nachrichten. Starten Sie die Konversation!',
            'type_message': 'Nachricht eingeben...',
            'message_label': 'Nachricht',
            'write_message_placeholder': 'Schreiben Sie Ihre Nachricht...',
            'max_characters': 'Maximal 2000 Zeichen.',
            'send_message': 'Nachricht senden',
            'send_failed': 'Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es erneut.',
            'conversation_with': 'Konversation mit %name%',
            'blocked_users': 'Blockierte Benutzer',
            'no_blocked_users': 'Sie haben keine Benutzer blockiert.',
            'blocked_on': 'Blockiert am',
            'unblock_confirm': 'Sind Sie sicher, dass Sie diesen Benutzer entsperren mochten?',
            'unblock': 'Entsperren',
            'report_conversation': 'Konversation melden',
            'report_confirm': 'Sind Sie sicher, dass Sie diese Konversation melden mochten?',
            'report_reason': 'Grund',
            'cancel': 'Abbrechen',
            'report_submit': 'Meldung absenden',
            'you_prefix': 'Sie:',
            'read_status': 'Gelesen',
            'sent_status': 'Gesendet',
            'typing': 'schreibt...',
            'actions': {
                'to_this_puzzler': 'An diesen Puzzler',
                'to_someone_else': 'An jemand anderen',
                'reserved': 'Reserviert',
                'sold_swapped': 'Verkauft/getauscht',
            },
            'conversation_partners': '- Aus Marketplace-Konversationen -',
            'system': {
                'listing_reserved_for_you': 'Dieses Puzzle wurde fur Sie reserviert.',
                'listing_reserved_for_this_puzzler': 'Dieses Puzzle wurde fur diesen Puzzler reserviert.',
                'listing_reserved_for_someone_else': 'Dieses Puzzle wurde fur jemand anderen reserviert.',
                'listing_reserved': 'Dieses Puzzle wurde reserviert.',
                'listing_reservation_removed': 'Dieses Puzzle ist nicht mehr reserviert und wieder verfugbar.',
                'listing_sold_to_you': 'Dieses Puzzle wurde an Sie verkauft/getauscht.',
                'listing_sold_to_this_puzzler': 'Sie haben dieses Puzzle an diesen Puzzler verkauft/getauscht.',
                'listing_sold_to_someone_else': 'Dieses Puzzle wurde an jemand anderen verkauft/getauscht.',
                'listing_sold': 'Dieses Puzzle wurde an jemand anderen verkauft/getauscht.',
                'preview': {
                    'listing_reserved': 'Puzzle wurde reserviert',
                    'listing_reservation_removed': 'Puzzle ist wieder verfugbar',
                    'listing_sold': 'Puzzle wurde verkauft/getauscht',
                },
            },
            'rate_transaction': 'Transaktion bewerten',
            'your_rating': 'Ihre Bewertung',
            'rate_pending': 'Bewerten',
        },

        'rating': {
            'title': 'Bewertungen',
            'title_for_player': 'Bewertungen fur %name%',
            'submit_button': 'Bewertung abgeben',
            'stars_label': 'Bewertung',
            'review_label': 'Rezension (optional)',
            'review_placeholder': 'Teilen Sie Ihre Erfahrung...',
            'thank_you': 'Vielen Dank fur Ihre Bewertung!',
            'cannot_rate': 'Sie konnen diese Transaktion nicht bewerten. Sie wurde moglicherweise bereits bewertet oder das Bewertungsfenster ist abgelaufen.',
            'invalid_stars': 'Bitte wahlen Sie eine Bewertung zwischen 1 und 5 Sternen.',
            'already_rated': 'Sie haben diese Transaktion bereits bewertet.',
            'expired': 'Das Bewertungsfenster ist abgelaufen (30 Tage).',
            'not_allowed': 'Sie durfen diese Transaktion nicht bewerten.',
            'no_ratings': 'Noch keine Bewertungen.',
            'rate_transaction': 'Diese Transaktion bewerten',
            'average': 'Durchschnittsbewertung',
            'count': '%count% Bewertungen',
            'count_singular': '1 Bewertung',
            'rate_your_transaction': 'Bewerten Sie Ihre Transaktion',
            'sold': 'Verkauft',
            'swapped': 'Getauscht',
            'sold_to': 'Verkauft an',
            'swapped_with': 'Getauscht mit',
            'bought_from': 'Gekauft von',
            'back_to_profile': 'Zuruck zum Profil',
            'cancel': 'Abbrechen',
            'optional_note': 'Optional. Max 500 Zeichen.',
            'role': {
                'seller': 'Verkaufer',
                'buyer': 'Kaufer',
            },
            'view_all': 'Alle anzeigen',
        },

        'marketplace': {
            'title': 'Marketplace',
            'meta': {
                'description': 'Durchsuchen Sie Puzzle zum Verkauf, Tausch oder kostenlos auf dem MySpeedPuzzling Marketplace.',
            },
            'all_countries': 'Alle Lander',
            'pcs': 'Stk',
            'filter': {
                'search_placeholder': 'Puzzle suchen...',
                'brand': 'Marke',
                'pieces': 'Teile',
                'type': 'Typ',
                'price': 'Preis',
                'condition': 'Zustand',
                'sort': 'Sortieren',
                'filters': 'Filter',
                'ships_to': 'Versendet nach',
                'ship_to_my_country': 'In mein Land versenden',
                'ship_to_my_country_disabled': 'In mein Land versenden (Land einstellen in',
                'set_country_link': 'Profil bearbeiten)',
            },
            'sort': {
                'newest': 'Neueste',
                'price_asc': 'Preis aufsteigend',
                'price_desc': 'Preis absteigend',
                'name_asc': 'Name A-Z',
                'name_desc': 'Name Z-A',
                'relevance': 'Relevanz',
            },
            'listing_type': {
                'sell': 'Verkauf',
                'swap': 'Tausch',
                'both': 'Verkauf / Tausch',
                'free': 'Kostenlos',
            },
            'condition': {
                'like_new': 'Wie neu',
                'normal': 'Normal',
                'not_so_good': 'Nicht so gut',
                'missing_pieces': 'Fehlende Teile',
            },
            'results': 'Insgesamt %count% Ergebnisse',
            'result': 'Insgesamt 1 Ergebnis',
            'no_results': 'Keine Angebote gefunden, die Ihren Filtern entsprechen.',
            'reserved': 'RESERVIERT',
            'contact_seller': 'Verkaufer kontaktieren',
            'price_not_disclosed': 'Preis nicht angegeben',
            'shipping_cost': 'Versand',
            'all_types': 'Alle Typen',
            'all_conditions': 'Alle Zustande',
            'all_brands': 'Alle Marken',
            'min': 'Min',
            'max': 'Max',
            'search_placeholder': 'Puzzlename, EAN...',
            'all': 'Alle',
            'my_offers': 'Meine Angebote',
            'manage_my_offers': 'Meine Angebote verwalten',
            'showing_offers_for': 'Angebote anzeigen fur',
            'show_all': 'Alle anzeigen',
            'load_more': 'Mehr laden',
            'disclaimer': 'MySpeedPuzzling ist kein Verkaufer und ubernimmt keine Verantwortung fur Transaktionen. Wir verbinden nur Menschen. Seien Sie immer vorsichtig und senden Sie kein Geld im Voraus.',
            'how_it_works_button': 'Wie funktioniert es?',
            'how_it_works': {
                'title': 'Wie der Marketplace funktioniert',
                'back_to_marketplace': 'Marketplace',
                'browse_marketplace': 'Marketplace durchsuchen',
                'intro': 'Der MySpeedPuzzling Marketplace ist ein Ort, an dem Puzzle-Liebhaber sich verbinden, um Puzzle zu verkaufen, zu tauschen oder zu verschenken. Hier ist alles, was Sie wissen mussen.',
                'step1': {
                    'title': 'Listen Sie Ihr Puzzle',
                    'description': 'Gehen Sie zu einem Puzzle in Ihrer Sammlung und fugen Sie es Ihrer Verkaufs-/Tauschliste hinzu. Stellen Sie sicher, dass Sie "Auf Marketplace anzeigen" aktivieren, damit andere Puzzler es finden konnen.',
                },
                'step2': {
                    'title': 'Richten Sie Ihr Profil ein',
                    'description': 'Fullen Sie vor dem Auflisten die Lander aus, in die Sie versenden, Ihre bevorzugte Wahrung und optional Ihre Versandkosten und Kontaktinformationen aus.',
                },
                'step3': {
                    'title': 'Chatten Sie mit anderen Puzzlern',
                    'description': 'Verwenden Sie den integrierten Chat, um direkt mit interessierten Kaufern oder Verkaufern auf der Plattform zu kommunizieren. Sie konnen auch ausserhalb der Plattform Dinge vereinbaren.',
                },
                'step4': {
                    'title': 'Puzzle reservieren',
                    'description': 'Wenn Sie sich auf einen Deal einigen, markieren Sie das Puzzle als reserviert. Dies lasst andere Puzzler wissen, dass der Artikel vergeben ist.',
                },
                'step5': {
                    'title': 'Transaktion abschliessen',
                    'description': 'Sobald das Puzzle verkauft oder getauscht wurde, markieren Sie es als abgeschlossen. Das Puzzle wird automatisch aus Ihrer Sammlung entfernt und der Kaufer kann es direkt zu seiner hinzufugen.',
                },
                'step6': {
                    'title': 'Bewertung hinterlassen',
                    'description': 'Vergessen Sie nach der Transaktion nicht, den anderen Puzzler zu bewerten! Bewertungen helfen, Vertrauen in der Community aufzubauen.',
                },
                'community': {
                    'title': 'Seien Sie freundlich und haben Sie Spass!',
                    'description': 'Wir sind alle hier, weil wir Puzzle lieben. Puzzle sollen Freude bringen, und so auch dieser Marketplace. Seien Sie nett, geduldig und geniessen Sie die Verbindung mit Puzzle-Enthusiasten aus aller Welt.',
                },
                'safety': {
                    'title': 'Bleiben Sie sicher',
                    'description': 'MySpeedPuzzling ist nicht verantwortlich fur Transaktionen. Wir sind hier, um Puzzler zu verbinden, aber alle Geschafte sind zwischen Ihnen und der anderen Partei. Seien Sie immer vorsichtig mit Geldtransaktionen.',
                    'block_info': 'Wenn Sie jemals unangemessenes Verhalten feststellen, konnen Sie die Konversation blockieren. Die andere Person wird nie erfahren, dass sie blockiert wurde.',
                },
            },
        },

        'moderation': {
            'report_submitted': 'Meldung wurde erfolgreich gesendet. Unser Team wird sie prufen.',
            'report_resolved': 'Meldung wurde gelost.',
            'report_dismissed': 'Meldung wurde abgewiesen.',
            'warning_issued': 'Verwarnung wurde ausgesprochen.',
            'user_muted': 'Benutzer wurde fur %days% Tage stummgeschaltet.',
            'user_unmuted': 'Benutzer wurde entstummgeschaltet.',
            'marketplace_banned': 'Benutzer wurde vom Marketplace ausgeschlossen.',
            'ban_lifted': 'Marketplace-Sperre wurde aufgehoben.',
            'listing_removed': 'Angebot wurde entfernt.',
        },
    },

    'es': {
        'back_to': 'Volver a',
        'edit_my_puzzle_offer': 'Editar mi oferta de puzzle',

        'edit_profile': {
            'messaging_notifications': 'Mensajes y notificaciones',
            'allow_direct_messages': 'Permitir que otros usuarios me envien mensajes directos',
            'email_notifications': 'Enviarme notificaciones por correo electronico sobre mensajes no leidos',
        },

        'membership': {
            'have_voucher_code': 'Tienes un codigo de cupon?',
            'redeem_voucher': 'Canjear cupon',
        },

        'puzzler_offers': {
            'column': {
                'comment': 'Comentario',
            },
            'offers_link': 'Ofertas (%count%)',
            'view_all': 'Ver las %count% ofertas',
            'view_on_marketplace': 'Ver en el marketplace',
        },

        'sell_swap_list': {
            'form': {
                'publish_on_marketplace': 'Publicar en el Marketplace',
                'publish_on_marketplace_help': 'Cuando esta habilitado, este articulo tambien aparecera en el marketplace publico.',
            },
            'on_marketplace': 'En el Marketplace',
            'reserved': 'RESERVADO',
            'reserved.success': 'La oferta fue marcada como reservada.',
            'reservation_removed.success': 'El puzzle ya no esta reservado y esta disponible nuevamente.',
            'mark_reserved': {
                'button': 'Marcar como reservado',
                'title': 'Marcar como reservado',
                'confirm': 'Confirmar reserva',
                'reserved_for_input': 'Reservado para',
                'reserved_for_input_help': 'Introduce el codigo del jugador (ej. #ABC123) o nombre. Deja vacio si no se conoce.',
                'reserved_for_input_placeholder': '#ABC123 o nombre...',
            },
            'remove_reservation': {
                'button': 'Eliminar reserva',
            },
            'settings': {
                'ships_to': 'Envia a',
                'shipping_countries': 'Paises a los que envias',
                'shipping_cost': 'Informacion de costo de envio',
                'shipping_cost_placeholder': 'ej. Envio gratuito desde 50 EUR, tarifa plana 5 EUR',
                'region': {
                    'central_europe': 'Europa Central',
                    'western_europe': 'Europa Occidental',
                    'southern_europe': 'Europa del Sur',
                    'northern_europe': 'Europa del Norte',
                    'eastern_europe': 'Europa del Este',
                    'north_america': 'Norteamerica',
                    'rest_of_world': 'Resto del mundo',
                },
            },
            'settings_alert': {
                'title': 'Ya casi!',
                'subtitle': 'Por favor completa estos pasos para que tus puzzles puedan ser descubiertos en el Marketplace.',
                'missing_currency': 'Configura tu moneda',
                'missing_shipping_countries': 'Agrega paises de envio',
                'cta': 'Completar configuracion',
            },
        },

        'messaging': {
            'request_accepted': 'Solicitud de mensaje aceptada.',
            'request_ignored': 'Solicitud de mensaje ignorada.',
            'request_sent': 'Solicitud de mensaje enviada exitosamente.',
            'message_empty': 'El mensaje no puede estar vacio.',
            'user_blocked': 'El usuario ha sido bloqueado.',
            'user_unblocked': 'El usuario ha sido desbloqueado.',
            'cannot_message_user': 'No puedes enviar mensajes a este usuario.',
            'direct_messages_disabled': 'Este usuario no acepta mensajes directos.',
            'pending_request_exists': 'Ya tienes una solicitud de mensaje pendiente con este usuario.',
            'cannot_contact_yourself': 'No puedes contactarte a ti mismo.',
            'conversations': 'Conversaciones',
            'requests': 'Solicitudes',
            'title': 'Mensajes',
            'send': 'Enviar',
            'send_placeholder': 'Escribe tu mensaje...',
            'block_user': 'Bloquear usuario',
            'block_confirm': 'Estas seguro de que quieres bloquear a este usuario?',
            'report': 'Reportar',
            'report_reason_placeholder': 'Describe por que estas reportando esta conversacion...',
            'report_button': 'Enviar reporte',
            'accept': 'Aceptar',
            'ignore': 'Ignorar',
            'new_message': 'Nuevo mensaje',
            'no_conversations': 'Aun no tienes conversaciones.',
            'no_requests': 'No tienes solicitudes pendientes.',
            'pending_status': 'Pendiente',
            'pending_notice': 'Esta solicitud de conversacion esta esperando aprobacion.',
            'ignored': 'Ignorado',
            'ignored_status': 'Ignorado',
            'ignored_notice': 'Esta solicitud de conversacion fue ignorada.',
            'no_conversations_yet': 'Aun no hay conversaciones.',
            'no_pending_requests': 'No hay solicitudes de mensaje pendientes.',
            'no_ignored_conversations': 'No hay conversaciones ignoradas.',
            'back_to_messages': 'Mensajes',
            'interested_in_puzzle': '%name% esta interesado en tu puzzle',
            'wants_to_message': '%name% quiere enviarte un mensaje',
            'pending_request_notice': 'Esta es una solicitud de mensaje.',
            'accept_disclaimer': 'Cuando aceptes, el remitente vera que estas activo y has leido los mensajes.',
            'waiting_for_acceptance': 'Esperando a que %name% acepte tu solicitud de mensaje.',
            'view': 'Ver',
            'no_messages_yet': 'Aun no hay mensajes. Inicia la conversacion!',
            'type_message': 'Escribe un mensaje...',
            'message_label': 'Mensaje',
            'write_message_placeholder': 'Escribe tu mensaje...',
            'max_characters': 'Maximo 2000 caracteres.',
            'send_message': 'Enviar mensaje',
            'send_failed': 'No se pudo enviar el mensaje. Por favor intenta de nuevo.',
            'conversation_with': 'Conversacion con %name%',
            'blocked_users': 'Usuarios bloqueados',
            'no_blocked_users': 'No has bloqueado a ningun usuario.',
            'blocked_on': 'Bloqueado el',
            'unblock_confirm': 'Estas seguro de que quieres desbloquear a este usuario?',
            'unblock': 'Desbloquear',
            'report_conversation': 'Reportar conversacion',
            'report_confirm': 'Estas seguro de que quieres reportar esta conversacion?',
            'report_reason': 'Razon',
            'cancel': 'Cancelar',
            'report_submit': 'Enviar reporte',
            'you_prefix': 'Tu:',
            'read_status': 'Leido',
            'sent_status': 'Enviado',
            'typing': 'escribiendo...',
            'actions': {
                'to_this_puzzler': 'A este puzzler',
                'to_someone_else': 'A alguien mas',
                'reserved': 'Reservado',
                'sold_swapped': 'Vendido/intercambiado',
            },
            'conversation_partners': '- De conversaciones del marketplace -',
            'system': {
                'listing_reserved_for_you': 'Este puzzle fue reservado para ti.',
                'listing_reserved_for_this_puzzler': 'Este puzzle fue reservado para este puzzler.',
                'listing_reserved_for_someone_else': 'Este puzzle fue reservado para alguien mas.',
                'listing_reserved': 'Este puzzle fue reservado.',
                'listing_reservation_removed': 'Este puzzle ya no esta reservado y esta disponible nuevamente.',
                'listing_sold_to_you': 'Este puzzle fue vendido/intercambiado para ti.',
                'listing_sold_to_this_puzzler': 'Vendiste/intercambiaste este puzzle a este puzzler.',
                'listing_sold_to_someone_else': 'Este puzzle fue vendido/intercambiado a alguien mas.',
                'listing_sold': 'Este puzzle fue vendido/intercambiado a alguien mas.',
                'preview': {
                    'listing_reserved': 'Puzzle fue reservado',
                    'listing_reservation_removed': 'Puzzle esta disponible nuevamente',
                    'listing_sold': 'Puzzle fue vendido/intercambiado',
                },
            },
            'rate_transaction': 'Calificar transaccion',
            'your_rating': 'Tu calificacion',
            'rate_pending': 'Calificar',
        },

        'rating': {
            'title': 'Calificaciones',
            'title_for_player': 'Calificaciones de %name%',
            'submit_button': 'Enviar calificacion',
            'stars_label': 'Calificacion',
            'review_label': 'Resena (opcional)',
            'review_placeholder': 'Comparte tu experiencia...',
            'thank_you': 'Gracias por tu calificacion!',
            'cannot_rate': 'No puedes calificar esta transaccion. Es posible que ya haya sido calificada o que el plazo haya expirado.',
            'invalid_stars': 'Por favor selecciona una calificacion entre 1 y 5 estrellas.',
            'already_rated': 'Ya has calificado esta transaccion.',
            'expired': 'El plazo de calificacion ha expirado (30 dias).',
            'not_allowed': 'No tienes permiso para calificar esta transaccion.',
            'no_ratings': 'Aun no hay calificaciones.',
            'rate_transaction': 'Calificar esta transaccion',
            'average': 'Calificacion promedio',
            'count': '%count% calificaciones',
            'count_singular': '1 calificacion',
            'rate_your_transaction': 'Califica tu transaccion',
            'sold': 'Vendido',
            'swapped': 'Intercambiado',
            'sold_to': 'Vendido a',
            'swapped_with': 'Intercambiado con',
            'bought_from': 'Comprado de',
            'back_to_profile': 'Volver al perfil',
            'cancel': 'Cancelar',
            'optional_note': 'Opcional. Max 500 caracteres.',
            'role': {
                'seller': 'Vendedor',
                'buyer': 'Comprador',
            },
            'view_all': 'Ver todo',
        },

        'marketplace': {
            'title': 'Marketplace',
            'meta': {
                'description': 'Explora puzzles en venta, intercambio o gratis en el marketplace de MySpeedPuzzling.',
            },
            'all_countries': 'Todos los paises',
            'pcs': 'pzs',
            'filter': {
                'search_placeholder': 'Buscar puzzles...',
                'brand': 'Marca',
                'pieces': 'Piezas',
                'type': 'Tipo',
                'price': 'Precio',
                'condition': 'Estado',
                'sort': 'Ordenar',
                'filters': 'Filtros',
                'ships_to': 'Envia a',
                'ship_to_my_country': 'Envio a mi pais',
                'ship_to_my_country_disabled': 'Envio a mi pais (configura tu pais en',
                'set_country_link': 'Editar perfil)',
            },
            'sort': {
                'newest': 'Mas recientes',
                'price_asc': 'Precio ascendente',
                'price_desc': 'Precio descendente',
                'name_asc': 'Nombre A-Z',
                'name_desc': 'Nombre Z-A',
                'relevance': 'Relevancia',
            },
            'listing_type': {
                'sell': 'Venta',
                'swap': 'Intercambio',
                'both': 'Venta / Intercambio',
                'free': 'Gratis',
            },
            'condition': {
                'like_new': 'Como nuevo',
                'normal': 'Normal',
                'not_so_good': 'No muy bueno',
                'missing_pieces': 'Piezas faltantes',
            },
            'results': 'Total %count% resultados',
            'result': 'Total 1 resultado',
            'no_results': 'No se encontraron ofertas que coincidan con tus filtros.',
            'reserved': 'RESERVADO',
            'contact_seller': 'Contactar vendedor',
            'price_not_disclosed': 'Precio no definido',
            'shipping_cost': 'Envio',
            'all_types': 'Todos los tipos',
            'all_conditions': 'Todos los estados',
            'all_brands': 'Todas las marcas',
            'min': 'Min',
            'max': 'Max',
            'search_placeholder': 'Nombre del puzzle, EAN...',
            'all': 'Todos',
            'my_offers': 'Mis ofertas',
            'manage_my_offers': 'Administrar mis ofertas',
            'showing_offers_for': 'Mostrando ofertas para',
            'show_all': 'Mostrar todo',
            'load_more': 'Cargar mas',
            'disclaimer': 'MySpeedPuzzling no es un vendedor y no se responsabiliza por las transacciones. Solo conectamos personas. Ten siempre cuidado y no envies dinero por adelantado.',
            'how_it_works_button': 'Como funciona?',
            'how_it_works': {
                'title': 'Como funciona el Marketplace',
                'back_to_marketplace': 'Marketplace',
                'browse_marketplace': 'Explorar el Marketplace',
                'intro': 'El Marketplace de MySpeedPuzzling es un lugar donde los amantes de los puzzles se conectan para vender, intercambiar o regalar puzzles. Aqui esta todo lo que necesitas saber.',
                'step1': {
                    'title': 'Publica tu puzzle',
                    'description': 'Ve a cualquier puzzle en tu coleccion y agregalo a tu lista de venta/intercambio. Asegurate de marcar "Mostrar en Marketplace" para que otros puzzlers lo encuentren.',
                },
                'step2': {
                    'title': 'Configura tu perfil',
                    'description': 'Antes de publicar, completa los paises a los que envias, tu moneda preferida y opcionalmente tus costos de envio e informacion de contacto.',
                },
                'step3': {
                    'title': 'Chatea con otros puzzlers',
                    'description': 'Usa el chat integrado para comunicarte directamente con compradores o vendedores interesados aqui en la plataforma. Tambien puedes organizar cosas fuera de la plataforma si lo prefieres.',
                },
                'step4': {
                    'title': 'Reserva puzzles',
                    'description': 'Cuando acuerden un trato, marca el puzzle como reservado. Esto les dice a otros puzzlers que el articulo esta apartado mientras se completa la transaccion.',
                },
                'step5': {
                    'title': 'Completa la transaccion',
                    'description': 'Una vez vendido o intercambiado el puzzle, marcalo como terminado. El puzzle se eliminara automaticamente de tu coleccion y el comprador puede agregarlo a la suya.',
                },
                'step6': {
                    'title': 'Deja una calificacion',
                    'description': 'Despues de la transaccion, no olvides calificar al otro puzzler! Las calificaciones ayudan a construir confianza en la comunidad.',
                },
                'community': {
                    'title': 'Se amigable y diviertete!',
                    'description': 'Todos estamos aqui porque amamos los puzzles. Los puzzles estan hechos para traer alegria, y tambien este marketplace. Se amable, paciente y disfruta conectando con entusiastas de puzzles de todo el mundo.',
                },
                'safety': {
                    'title': 'Mantente seguro',
                    'description': 'MySpeedPuzzling no es responsable de ninguna transaccion. Estamos aqui para conectar puzzlers, pero todos los tratos son entre tu y la otra parte. Siempre ten cuidado con las transacciones de dinero.',
                    'block_info': 'Si alguna vez encuentras un comportamiento inadecuado, puedes bloquear la conversacion. La otra persona nunca sabra que fue bloqueada.',
                },
            },
        },

        'moderation': {
            'report_submitted': 'Reporte enviado exitosamente. Nuestro equipo lo revisara.',
            'report_resolved': 'El reporte ha sido resuelto.',
            'report_dismissed': 'El reporte ha sido descartado.',
            'warning_issued': 'Se ha emitido una advertencia.',
            'user_muted': 'El usuario ha sido silenciado por %days% dias.',
            'user_unmuted': 'El usuario ha sido desilenciado.',
            'marketplace_banned': 'El usuario ha sido prohibido del marketplace.',
            'ban_lifted': 'La prohibicion del marketplace ha sido levantada.',
            'listing_removed': 'La oferta ha sido eliminada.',
        },
    },

    'fr': {
        'back_to': 'Retour a',
        'edit_my_puzzle_offer': 'Modifier mon offre de puzzle',

        'edit_profile': {
            'messaging_notifications': 'Messagerie et notifications',
            'allow_direct_messages': 'Autoriser les autres utilisateurs a m\'envoyer des messages directs',
            'email_notifications': 'M\'envoyer des notifications par e-mail pour les messages non lus',
        },

        'membership': {
            'have_voucher_code': 'Vous avez un code promo ?',
            'redeem_voucher': 'Utiliser un bon',
        },

        'puzzler_offers': {
            'column': {
                'comment': 'Commentaire',
            },
            'offers_link': 'Offres (%count%)',
            'view_all': 'Voir les %count% offres',
            'view_on_marketplace': 'Voir sur le marketplace',
        },

        'sell_swap_list': {
            'form': {
                'publish_on_marketplace': 'Publier sur le Marketplace',
                'publish_on_marketplace_help': 'Lorsque cette option est activee, cet article apparaitra egalement sur le marketplace public.',
            },
            'on_marketplace': 'Sur le Marketplace',
            'reserved': 'RESERVE',
            'reserved.success': 'L\'offre a ete marquee comme reservee.',
            'reservation_removed.success': 'Le puzzle n\'est plus reserve et est a nouveau disponible.',
            'mark_reserved': {
                'button': 'Marquer comme reserve',
                'title': 'Marquer comme reserve',
                'confirm': 'Confirmer la reservation',
                'reserved_for_input': 'Reserve pour',
                'reserved_for_input_help': 'Entrez le code du joueur (ex. #ABC123) ou le nom. Laissez vide si inconnu.',
                'reserved_for_input_placeholder': '#ABC123 ou nom...',
            },
            'remove_reservation': {
                'button': 'Supprimer la reservation',
            },
            'settings': {
                'ships_to': 'Expedie vers',
                'shipping_countries': 'Pays de livraison',
                'shipping_cost': 'Info frais de port',
                'shipping_cost_placeholder': 'ex. Livraison gratuite a partir de 50 EUR, forfait 5 EUR',
                'region': {
                    'central_europe': 'Europe centrale',
                    'western_europe': 'Europe de l\'Ouest',
                    'southern_europe': 'Europe du Sud',
                    'northern_europe': 'Europe du Nord',
                    'eastern_europe': 'Europe de l\'Est',
                    'north_america': 'Amerique du Nord',
                    'rest_of_world': 'Reste du monde',
                },
            },
            'settings_alert': {
                'title': 'Presque termine !',
                'subtitle': 'Veuillez completer ces etapes pour que vos puzzles puissent etre decouverts sur le Marketplace.',
                'missing_currency': 'Definir votre devise',
                'missing_shipping_countries': 'Ajouter les pays de livraison',
                'cta': 'Terminer la configuration',
            },
        },

        'messaging': {
            'request_accepted': 'Demande de message acceptee.',
            'request_ignored': 'Demande de message ignoree.',
            'request_sent': 'Demande de message envoyee avec succes.',
            'message_empty': 'Le message ne peut pas etre vide.',
            'user_blocked': 'L\'utilisateur a ete bloque.',
            'user_unblocked': 'L\'utilisateur a ete debloque.',
            'cannot_message_user': 'Vous ne pouvez pas envoyer de message a cet utilisateur.',
            'direct_messages_disabled': 'Cet utilisateur n\'accepte pas les messages directs.',
            'pending_request_exists': 'Vous avez deja une demande de message en attente avec cet utilisateur.',
            'cannot_contact_yourself': 'Vous ne pouvez pas vous contacter vous-meme.',
            'conversations': 'Conversations',
            'requests': 'Demandes',
            'title': 'Messages',
            'send': 'Envoyer',
            'send_placeholder': 'Ecrivez votre message...',
            'block_user': 'Bloquer l\'utilisateur',
            'block_confirm': 'Etes-vous sur de vouloir bloquer cet utilisateur ?',
            'report': 'Signaler',
            'report_reason_placeholder': 'Decrivez pourquoi vous signalez cette conversation...',
            'report_button': 'Envoyer le signalement',
            'accept': 'Accepter',
            'ignore': 'Ignorer',
            'new_message': 'Nouveau message',
            'no_conversations': 'Vous n\'avez pas encore de conversations.',
            'no_requests': 'Vous n\'avez aucune demande en attente.',
            'pending_status': 'En attente',
            'pending_notice': 'Cette demande de conversation est en attente d\'approbation.',
            'ignored': 'Ignore',
            'ignored_status': 'Ignore',
            'ignored_notice': 'Cette demande de conversation a ete ignoree.',
            'no_conversations_yet': 'Pas encore de conversations.',
            'no_pending_requests': 'Aucune demande de message en attente.',
            'no_ignored_conversations': 'Aucune conversation ignoree.',
            'back_to_messages': 'Messages',
            'interested_in_puzzle': '%name% est interesse par votre puzzle',
            'wants_to_message': '%name% souhaite vous envoyer un message',
            'pending_request_notice': 'Ceci est une demande de message.',
            'accept_disclaimer': 'En acceptant, l\'expediteur verra que vous etes actif et avez lu les messages.',
            'waiting_for_acceptance': 'En attente de l\'acceptation de votre demande de message par %name%.',
            'view': 'Voir',
            'no_messages_yet': 'Pas encore de messages. Commencez la conversation !',
            'type_message': 'Ecrivez un message...',
            'message_label': 'Message',
            'write_message_placeholder': 'Ecrivez votre message...',
            'max_characters': 'Maximum 2000 caracteres.',
            'send_message': 'Envoyer le message',
            'send_failed': 'Echec de l\'envoi du message. Veuillez reessayer.',
            'conversation_with': 'Conversation avec %name%',
            'blocked_users': 'Utilisateurs bloques',
            'no_blocked_users': 'Vous n\'avez bloque aucun utilisateur.',
            'blocked_on': 'Bloque le',
            'unblock_confirm': 'Etes-vous sur de vouloir debloquer cet utilisateur ?',
            'unblock': 'Debloquer',
            'report_conversation': 'Signaler la conversation',
            'report_confirm': 'Etes-vous sur de vouloir signaler cette conversation ?',
            'report_reason': 'Raison',
            'cancel': 'Annuler',
            'report_submit': 'Envoyer le signalement',
            'you_prefix': 'Vous :',
            'read_status': 'Lu',
            'sent_status': 'Envoye',
            'typing': 'ecrit...',
            'actions': {
                'to_this_puzzler': 'A ce puzzleur',
                'to_someone_else': 'A quelqu\'un d\'autre',
                'reserved': 'Reserve',
                'sold_swapped': 'Vendu/echange',
            },
            'conversation_partners': '- Des conversations du marketplace -',
            'system': {
                'listing_reserved_for_you': 'Ce puzzle a ete reserve pour vous.',
                'listing_reserved_for_this_puzzler': 'Ce puzzle a ete reserve pour ce puzzleur.',
                'listing_reserved_for_someone_else': 'Ce puzzle a ete reserve pour quelqu\'un d\'autre.',
                'listing_reserved': 'Ce puzzle a ete reserve.',
                'listing_reservation_removed': 'Ce puzzle n\'est plus reserve et est a nouveau disponible.',
                'listing_sold_to_you': 'Ce puzzle a ete vendu/echange pour vous.',
                'listing_sold_to_this_puzzler': 'Vous avez vendu/echange ce puzzle a ce puzzleur.',
                'listing_sold_to_someone_else': 'Ce puzzle a ete vendu/echange a quelqu\'un d\'autre.',
                'listing_sold': 'Ce puzzle a ete vendu/echange a quelqu\'un d\'autre.',
                'preview': {
                    'listing_reserved': 'Puzzle a ete reserve',
                    'listing_reservation_removed': 'Puzzle est a nouveau disponible',
                    'listing_sold': 'Puzzle a ete vendu/echange',
                },
            },
            'rate_transaction': 'Evaluer la transaction',
            'your_rating': 'Votre evaluation',
            'rate_pending': 'Evaluer',
        },

        'rating': {
            'title': 'Evaluations',
            'title_for_player': 'Evaluations de %name%',
            'submit_button': 'Soumettre l\'evaluation',
            'stars_label': 'Evaluation',
            'review_label': 'Avis (optionnel)',
            'review_placeholder': 'Partagez votre experience...',
            'thank_you': 'Merci pour votre evaluation !',
            'cannot_rate': 'Vous ne pouvez pas evaluer cette transaction. Elle a peut-etre deja ete evaluee ou le delai a expire.',
            'invalid_stars': 'Veuillez selectionner une evaluation entre 1 et 5 etoiles.',
            'already_rated': 'Vous avez deja evalue cette transaction.',
            'expired': 'Le delai d\'evaluation a expire (30 jours).',
            'not_allowed': 'Vous n\'etes pas autorise a evaluer cette transaction.',
            'no_ratings': 'Pas encore d\'evaluations.',
            'rate_transaction': 'Evaluer cette transaction',
            'average': 'Evaluation moyenne',
            'count': '%count% evaluations',
            'count_singular': '1 evaluation',
            'rate_your_transaction': 'Evaluez votre transaction',
            'sold': 'Vendu',
            'swapped': 'Echange',
            'sold_to': 'Vendu a',
            'swapped_with': 'Echange avec',
            'bought_from': 'Achete de',
            'back_to_profile': 'Retour au profil',
            'cancel': 'Annuler',
            'optional_note': 'Optionnel. Max 500 caracteres.',
            'role': {
                'seller': 'Vendeur',
                'buyer': 'Acheteur',
            },
            'view_all': 'Voir tout',
        },

        'marketplace': {
            'title': 'Marketplace',
            'meta': {
                'description': 'Parcourez les puzzles en vente, echange ou gratuits sur le marketplace MySpeedPuzzling.',
            },
            'all_countries': 'Tous les pays',
            'pcs': 'pcs',
            'filter': {
                'search_placeholder': 'Rechercher des puzzles...',
                'brand': 'Marque',
                'pieces': 'Pieces',
                'type': 'Type',
                'price': 'Prix',
                'condition': 'Etat',
                'sort': 'Trier',
                'filters': 'Filtres',
                'ships_to': 'Expedie vers',
                'ship_to_my_country': 'Expedier dans mon pays',
                'ship_to_my_country_disabled': 'Expedier dans mon pays (definir votre pays dans',
                'set_country_link': 'Modifier le profil)',
            },
            'sort': {
                'newest': 'Plus recents',
                'price_asc': 'Prix croissant',
                'price_desc': 'Prix decroissant',
                'name_asc': 'Nom A-Z',
                'name_desc': 'Nom Z-A',
                'relevance': 'Pertinence',
            },
            'listing_type': {
                'sell': 'Vente',
                'swap': 'Echange',
                'both': 'Vente / Echange',
                'free': 'Gratuit',
            },
            'condition': {
                'like_new': 'Comme neuf',
                'normal': 'Normal',
                'not_so_good': 'Pas tres bon',
                'missing_pieces': 'Pieces manquantes',
            },
            'results': 'Total %count% resultats',
            'result': 'Total 1 resultat',
            'no_results': 'Aucune offre trouvee correspondant a vos filtres.',
            'reserved': 'RESERVE',
            'contact_seller': 'Contacter le vendeur',
            'price_not_disclosed': 'Prix non defini',
            'shipping_cost': 'Livraison',
            'all_types': 'Tous les types',
            'all_conditions': 'Tous les etats',
            'all_brands': 'Toutes les marques',
            'min': 'Min',
            'max': 'Max',
            'search_placeholder': 'Nom du puzzle, EAN...',
            'all': 'Tous',
            'my_offers': 'Mes offres',
            'manage_my_offers': 'Gerer mes offres',
            'showing_offers_for': 'Affichage des offres pour',
            'show_all': 'Tout afficher',
            'load_more': 'Charger plus',
            'disclaimer': 'MySpeedPuzzling n\'est pas un vendeur et n\'assume aucune responsabilite pour les transactions. Nous ne faisons que connecter les gens. Soyez toujours prudent et n\'envoyez jamais d\'argent a l\'avance.',
            'how_it_works_button': 'Comment ca marche ?',
            'how_it_works': {
                'title': 'Comment fonctionne le Marketplace',
                'back_to_marketplace': 'Marketplace',
                'browse_marketplace': 'Parcourir le Marketplace',
                'intro': 'Le Marketplace MySpeedPuzzling est un lieu ou les amateurs de puzzles se connectent pour vendre, echanger ou donner des puzzles. Voici tout ce que vous devez savoir.',
                'step1': {
                    'title': 'Publiez votre puzzle',
                    'description': 'Allez sur n\'importe quel puzzle de votre collection et ajoutez-le a votre liste de vente/echange. Assurez-vous de cocher "Afficher sur le Marketplace" pour que d\'autres puzzleurs puissent le trouver.',
                },
                'step2': {
                    'title': 'Configurez votre profil',
                    'description': 'Avant de publier, remplissez les pays de livraison, votre devise preferee et eventuellement vos frais de port et informations de contact.',
                },
                'step3': {
                    'title': 'Chattez avec d\'autres puzzleurs',
                    'description': 'Utilisez le chat integre pour communiquer directement avec les acheteurs ou vendeurs interesses sur la plateforme. Vous pouvez aussi organiser les choses en dehors de la plateforme si vous preferez.',
                },
                'step4': {
                    'title': 'Reservez des puzzles',
                    'description': 'Lorsque vous vous mettez d\'accord sur un deal, marquez le puzzle comme reserve. Cela indique aux autres puzzleurs que l\'article est pris pendant que la transaction est en cours.',
                },
                'step5': {
                    'title': 'Finalisez la transaction',
                    'description': 'Une fois le puzzle vendu ou echange, marquez-le comme termine. Le puzzle sera automatiquement retire de votre collection et l\'acheteur pourra l\'ajouter directement a la sienne.',
                },
                'step6': {
                    'title': 'Laissez une evaluation',
                    'description': 'Apres la transaction, n\'oubliez pas d\'evaluer l\'autre puzzleur ! Les evaluations aident a construire la confiance dans la communaute.',
                },
                'community': {
                    'title': 'Soyez sympathique et amusez-vous !',
                    'description': 'Nous sommes tous ici parce que nous aimons les puzzles. Les puzzles sont faits pour apporter de la joie, et ce marketplace aussi. Soyez gentil, patient et profitez de la connexion avec des passionnes de puzzles du monde entier.',
                },
                'safety': {
                    'title': 'Restez en securite',
                    'description': 'MySpeedPuzzling n\'est pas responsable des transactions. Nous sommes la pour connecter les puzzleurs, mais toutes les transactions sont entre vous et l\'autre partie. Soyez toujours prudent avec les transactions financieres.',
                    'block_info': 'Si vous rencontrez un comportement inapproprie, vous pouvez bloquer la conversation. L\'autre personne ne saura jamais qu\'elle a ete bloquee.',
                },
            },
        },

        'moderation': {
            'report_submitted': 'Signalement envoye avec succes. Notre equipe l\'examinera.',
            'report_resolved': 'Le signalement a ete resolu.',
            'report_dismissed': 'Le signalement a ete rejete.',
            'warning_issued': 'Un avertissement a ete emis.',
            'user_muted': 'L\'utilisateur a ete mis en sourdine pour %days% jours.',
            'user_unmuted': 'L\'utilisateur a ete retire de la sourdine.',
            'marketplace_banned': 'L\'utilisateur a ete banni du marketplace.',
            'ban_lifted': 'L\'interdiction du marketplace a ete levee.',
            'listing_removed': 'L\'offre a ete supprimee.',
        },
    },

    'ja': {
        'back_to': '戻る',
        'edit_my_puzzle_offer': 'パズルオファーを編集',

        'edit_profile': {
            'messaging_notifications': 'メッセージと通知',
            'allow_direct_messages': '他のユーザーからのダイレクトメッセージを許可する',
            'email_notifications': '未読メッセージについてメール通知を送信する',
        },

        'membership': {
            'have_voucher_code': 'バウチャーコードをお持ちですか？',
            'redeem_voucher': 'バウチャーを利用する',
        },

        'puzzler_offers': {
            'column': {
                'comment': 'コメント',
            },
            'offers_link': 'オファー（%count%）',
            'view_all': '%count%件のオファーをすべて見る',
            'view_on_marketplace': 'マーケットプレイスで見る',
        },

        'sell_swap_list': {
            'form': {
                'publish_on_marketplace': 'マーケットプレイスに公開',
                'publish_on_marketplace_help': '有効にすると、このアイテムは公開マーケットプレイスにも表示されます。',
            },
            'on_marketplace': 'マーケットプレイス掲載中',
            'reserved': '予約済み',
            'reserved.success': 'リスティングが予約済みとしてマークされました。',
            'reservation_removed.success': 'パズルの予約が解除され、再び利用可能になりました。',
            'mark_reserved': {
                'button': '予約済みにする',
                'title': '予約済みにする',
                'confirm': '予約を確認',
                'reserved_for_input': '予約先',
                'reserved_for_input_help': 'プレイヤーコード（例：#ABC123）または名前を入力してください。不明な場合は空欄にしてください。',
                'reserved_for_input_placeholder': '#ABC123または名前...',
            },
            'remove_reservation': {
                'button': '予約を解除',
            },
            'settings': {
                'ships_to': '発送先',
                'shipping_countries': '発送可能な国',
                'shipping_cost': '送料情報',
                'shipping_cost_placeholder': '例：50 EUR以上で送料無料、一律5 EUR',
                'region': {
                    'central_europe': '中央ヨーロッパ',
                    'western_europe': '西ヨーロッパ',
                    'southern_europe': '南ヨーロッパ',
                    'northern_europe': '北ヨーロッパ',
                    'eastern_europe': '東ヨーロッパ',
                    'north_america': '北米',
                    'rest_of_world': 'その他の地域',
                },
            },
            'settings_alert': {
                'title': 'もう少しです！',
                'subtitle': 'マーケットプレイスでパズルが見つかるように、これらのステップを完了してください。',
                'missing_currency': '通貨を設定',
                'missing_shipping_countries': '発送国を追加',
                'cta': '設定を完了',
            },
        },

        'messaging': {
            'request_accepted': 'メッセージリクエストが承認されました。',
            'request_ignored': 'メッセージリクエストが無視されました。',
            'request_sent': 'メッセージリクエストが正常に送信されました。',
            'message_empty': 'メッセージは空にできません。',
            'user_blocked': 'ユーザーがブロックされました。',
            'user_unblocked': 'ユーザーのブロックが解除されました。',
            'cannot_message_user': 'このユーザーにメッセージを送ることはできません。',
            'direct_messages_disabled': 'このユーザーはダイレクトメッセージを受け付けていません。',
            'pending_request_exists': 'このユーザーとの保留中のメッセージリクエストがあります。',
            'cannot_contact_yourself': '自分自身に連絡することはできません。',
            'conversations': '会話',
            'requests': 'リクエスト',
            'title': 'メッセージ',
            'send': '送信',
            'send_placeholder': 'メッセージを入力...',
            'block_user': 'ユーザーをブロック',
            'block_confirm': 'このユーザーをブロックしてもよろしいですか？',
            'report': '報告',
            'report_reason_placeholder': 'この会話を報告する理由を説明してください...',
            'report_button': '報告を送信',
            'accept': '承認',
            'ignore': '無視',
            'new_message': '新しいメッセージ',
            'no_conversations': 'まだ会話がありません。',
            'no_requests': '保留中のリクエストはありません。',
            'pending_status': '保留中',
            'pending_notice': 'この会話リクエストは承認待ちです。',
            'ignored': '無視済み',
            'ignored_status': '無視済み',
            'ignored_notice': 'この会話リクエストは無視されました。',
            'no_conversations_yet': 'まだ会話がありません。',
            'no_pending_requests': '保留中のメッセージリクエストはありません。',
            'no_ignored_conversations': '無視された会話はありません。',
            'back_to_messages': 'メッセージ',
            'interested_in_puzzle': '%name%があなたのパズルに興味を持っています',
            'wants_to_message': '%name%がメッセージを送りたいと思っています',
            'pending_request_notice': 'これはメッセージリクエストです。',
            'accept_disclaimer': '承認すると、送信者はあなたがアクティブでメッセージを読んだことがわかります。',
            'waiting_for_acceptance': '%name%があなたのメッセージリクエストを承認するのを待っています。',
            'view': '表示',
            'no_messages_yet': 'まだメッセージがありません。会話を始めましょう！',
            'type_message': 'メッセージを入力...',
            'message_label': 'メッセージ',
            'write_message_placeholder': 'メッセージを書いてください...',
            'max_characters': '最大2000文字。',
            'send_message': 'メッセージを送信',
            'send_failed': 'メッセージの送信に失敗しました。もう一度お試しください。',
            'conversation_with': '%name%との会話',
            'blocked_users': 'ブロックされたユーザー',
            'no_blocked_users': 'ブロックしたユーザーはいません。',
            'blocked_on': 'ブロック日',
            'unblock_confirm': 'このユーザーのブロックを解除してもよろしいですか？',
            'unblock': 'ブロック解除',
            'report_conversation': '会話を報告',
            'report_confirm': 'この会話を報告してもよろしいですか？',
            'report_reason': '理由',
            'cancel': 'キャンセル',
            'report_submit': '報告を送信',
            'you_prefix': 'あなた：',
            'read_status': '既読',
            'sent_status': '送信済み',
            'typing': '入力中...',
            'actions': {
                'to_this_puzzler': 'このパズラーに',
                'to_someone_else': '他の人に',
                'reserved': '予約済み',
                'sold_swapped': '販売/交換済み',
            },
            'conversation_partners': '- マーケットプレイスの会話から -',
            'system': {
                'listing_reserved_for_you': 'このパズルはあなたのために予約されました。',
                'listing_reserved_for_this_puzzler': 'このパズルはこのパズラーのために予約されました。',
                'listing_reserved_for_someone_else': 'このパズルは他の人のために予約されました。',
                'listing_reserved': 'このパズルは予約されました。',
                'listing_reservation_removed': 'このパズルの予約が解除され、再び利用可能になりました。',
                'listing_sold_to_you': 'このパズルはあなたに販売/交換されました。',
                'listing_sold_to_this_puzzler': 'このパズルをこのパズラーに販売/交換しました。',
                'listing_sold_to_someone_else': 'このパズルは他の人に販売/交換されました。',
                'listing_sold': 'このパズルは他の人に販売/交換されました。',
                'preview': {
                    'listing_reserved': 'パズルが予約されました',
                    'listing_reservation_removed': 'パズルが再び利用可能になりました',
                    'listing_sold': 'パズルが販売/交換されました',
                },
            },
            'rate_transaction': '取引を評価',
            'your_rating': 'あなたの評価',
            'rate_pending': '評価する',
        },

        'rating': {
            'title': '評価',
            'title_for_player': '%name%の評価',
            'submit_button': '評価を送信',
            'stars_label': '評価',
            'review_label': 'レビュー（任意）',
            'review_placeholder': '体験を共有してください...',
            'thank_you': '評価ありがとうございます！',
            'cannot_rate': 'この取引を評価できません。すでに評価済みか、評価期間が終了している可能性があります。',
            'invalid_stars': '1から5つ星の間で評価を選択してください。',
            'already_rated': 'この取引はすでに評価済みです。',
            'expired': '評価期間が終了しました（30日間）。',
            'not_allowed': 'この取引を評価する権限がありません。',
            'no_ratings': 'まだ評価がありません。',
            'rate_transaction': 'この取引を評価',
            'average': '平均評価',
            'count': '%count%件の評価',
            'count_singular': '1件の評価',
            'rate_your_transaction': '取引を評価してください',
            'sold': '販売済み',
            'swapped': '交換済み',
            'sold_to': '販売先',
            'swapped_with': '交換相手',
            'bought_from': '購入元',
            'back_to_profile': 'プロフィールに戻る',
            'cancel': 'キャンセル',
            'optional_note': '任意。最大500文字。',
            'role': {
                'seller': '売り手',
                'buyer': '買い手',
            },
            'view_all': 'すべて表示',
        },

        'marketplace': {
            'title': 'マーケットプレイス',
            'meta': {
                'description': 'MySpeedPuzzlingマーケットプレイスで販売、交換、無料のパズルを閲覧できます。',
            },
            'all_countries': 'すべての国',
            'pcs': '個',
            'filter': {
                'search_placeholder': 'パズルを検索...',
                'brand': 'ブランド',
                'pieces': 'ピース',
                'type': 'タイプ',
                'price': '価格',
                'condition': '状態',
                'sort': '並び替え',
                'filters': 'フィルター',
                'ships_to': '発送先',
                'ship_to_my_country': '自国への発送',
                'ship_to_my_country_disabled': '自国への発送（国を設定：',
                'set_country_link': 'プロフィール編集）',
            },
            'sort': {
                'newest': '最新',
                'price_asc': '価格（安い順）',
                'price_desc': '価格（高い順）',
                'name_asc': '名前 A-Z',
                'name_desc': '名前 Z-A',
                'relevance': '関連性',
            },
            'listing_type': {
                'sell': '販売',
                'swap': '交換',
                'both': '販売 / 交換',
                'free': '無料',
            },
            'condition': {
                'like_new': 'ほぼ新品',
                'normal': '通常',
                'not_so_good': 'あまり良くない',
                'missing_pieces': 'ピース欠品あり',
            },
            'results': '全%count%件の結果',
            'result': '全1件の結果',
            'no_results': 'フィルターに一致する出品が見つかりませんでした。',
            'reserved': '予約済み',
            'contact_seller': '売り手に連絡',
            'price_not_disclosed': '価格未設定',
            'shipping_cost': '送料',
            'all_types': 'すべてのタイプ',
            'all_conditions': 'すべての状態',
            'all_brands': 'すべてのブランド',
            'min': '最小',
            'max': '最大',
            'search_placeholder': 'パズル名、EAN...',
            'all': 'すべて',
            'my_offers': 'マイオファー',
            'manage_my_offers': 'マイオファーを管理',
            'showing_offers_for': 'オファーを表示中：',
            'show_all': 'すべて表示',
            'load_more': 'もっと読み込む',
            'disclaimer': 'MySpeedPuzzlingは販売者ではなく、取引に対する責任を負いません。人々をつなぐだけです。常に注意し、前払いでお金を送らないでください。',
            'how_it_works_button': '使い方',
            'how_it_works': {
                'title': 'マーケットプレイスの使い方',
                'back_to_marketplace': 'マーケットプレイス',
                'browse_marketplace': 'マーケットプレイスを閲覧',
                'intro': 'MySpeedPuzzlingマーケットプレイスは、パズル愛好家がパズルを販売、交換、譲渡するためにつながる場所です。始めるために必要なことをご紹介します。',
                'step1': {
                    'title': 'パズルを出品',
                    'description': 'コレクション内のパズルに移動して、販売/交換リストに追加してください。「マーケットプレイスに表示」にチェックを入れて、他のパズラーが見つけられるようにしましょう。',
                },
                'step2': {
                    'title': 'プロフィールを設定',
                    'description': '出品前に、発送可能な国、希望通貨、オプションで送料と連絡先情報を入力してください。',
                },
                'step3': {
                    'title': '他のパズラーとチャット',
                    'description': '内蔵チャットを使って、興味を持った買い手や売り手とプラットフォーム上で直接コミュニケーションできます。プラットフォーム外でやり取りすることもできます。',
                },
                'step4': {
                    'title': 'パズルを予約',
                    'description': '取引に同意したら、パズルを予約済みにマークしてください。これにより、取引完了まで他のパズラーにアイテムが確保済みであることを知らせます。',
                },
                'step5': {
                    'title': '取引を完了',
                    'description': 'パズルが販売または交換されたら、完了としてマークしてください。パズルはコレクションから自動的に削除され、買い手は直接自分のコレクションに追加できます。',
                },
                'step6': {
                    'title': '評価を残す',
                    'description': '取引後、相手のパズラーを評価することを忘れないでください！評価はコミュニティの信頼構築に役立ちます。',
                },
                'community': {
                    'title': 'フレンドリーに楽しみましょう！',
                    'description': '私たちは皆パズルが好きでここにいます。パズルは喜びをもたらすためのものであり、このマーケットプレイスも同様です。親切で忍耐強く、世界中のパズル愛好家とのつながりを楽しんでください。',
                },
                'safety': {
                    'title': '安全に利用しましょう',
                    'description': 'MySpeedPuzzlingは取引に対する責任を負いません。パズラーをつなぐためにここにいますが、すべての取引は当事者間のものです。金銭取引には常に注意してください。',
                    'block_info': '不適切な行為に遭遇した場合、会話をブロックできます。相手はブロックされたことを知ることはありません。',
                },
            },
        },

        'moderation': {
            'report_submitted': '報告が正常に送信されました。チームが確認します。',
            'report_resolved': '報告が解決されました。',
            'report_dismissed': '報告が却下されました。',
            'warning_issued': '警告が発行されました。',
            'user_muted': 'ユーザーが%days%日間ミュートされました。',
            'user_unmuted': 'ユーザーのミュートが解除されました。',
            'marketplace_banned': 'ユーザーがマーケットプレイスから禁止されました。',
            'ban_lifted': 'マーケットプレイスの禁止が解除されました。',
            'listing_removed': '出品が削除されました。',
        },
    },
}


# =============================================================================
# YAML helpers
# =============================================================================

class QuotedStr(str):
    """String that will be dumped with double quotes."""
    pass

def quoted_str_representer(dumper, data):
    return dumper.represent_scalar('tag:yaml.org,2002:str', data, style='"')

yaml.add_representer(QuotedStr, quoted_str_representer)


def convert_to_quoted(obj):
    """Recursively convert all string values to QuotedStr."""
    if isinstance(obj, dict):
        return {k: convert_to_quoted(v) for k, v in obj.items()}
    elif isinstance(obj, list):
        return [convert_to_quoted(item) for item in obj]
    elif isinstance(obj, str):
        return QuotedStr(obj)
    return obj


def deep_merge(base, additions):
    """Deep merge additions into base dict. Only adds new keys, never overwrites existing."""
    for key, value in additions.items():
        if key in base:
            if isinstance(base[key], dict) and isinstance(value, dict):
                deep_merge(base[key], value)
            # If key exists and is not a dict, skip (don't overwrite)
        else:
            base[key] = value
    return base


# =============================================================================
# Main processing
# =============================================================================

def process_emails():
    """Append unread_messages section to email locale files."""
    print("Processing email files...")

    for locale, translations in EMAIL_TRANSLATIONS.items():
        filepath = os.path.join(TRANSLATIONS_DIR, f'emails.{locale}.yml')

        if not os.path.exists(filepath):
            print(f"  WARNING: {filepath} does not exist, skipping")
            continue

        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()

        # Check if section already exists
        if 'unread_messages:' in content:
            print(f"  {locale}: unread_messages already exists, skipping")
            continue

        # Build the YAML block to append
        lines = ['\nunread_messages:']
        for key, value in translations['unread_messages'].items():
            # Escape single quotes by doubling them for YAML single-quoted strings
            # But use the same quoting style as the en file
            escaped = value.replace("'", "''")
            if '<a ' in value or '"' in value:
                # Use single quotes for values containing HTML with double quotes
                lines.append(f"    {key}: '{escaped}'")
            else:
                lines.append(f'    {key}: "{value}"')

        # Ensure file ends with newline before appending
        if not content.endswith('\n'):
            content += '\n'

        content += '\n'.join(lines) + '\n'

        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)

        print(f"  {locale}: appended unread_messages section")


def process_messages():
    """Deep merge new keys into messages locale files."""
    print("\nProcessing messages files...")

    for locale, translations in MESSAGES_TRANSLATIONS.items():
        filepath = os.path.join(TRANSLATIONS_DIR, f'messages.{locale}.yml')

        if not os.path.exists(filepath):
            print(f"  WARNING: {filepath} does not exist, skipping")
            continue

        # Read existing file
        with open(filepath, 'r', encoding='utf-8') as f:
            existing = yaml.safe_load(f)

        if existing is None:
            existing = {}

        # Deep merge new translations
        merged = deep_merge(existing, translations)

        # Convert all strings to QuotedStr for consistent double-quoting
        merged = convert_to_quoted(merged)

        # Dump with our custom settings
        output = yaml.dump(
            merged,
            default_flow_style=False,
            allow_unicode=True,
            width=1000,
            sort_keys=False,
        )

        # Ensure trailing newline
        if not output.endswith('\n'):
            output += '\n'

        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(output)

        print(f"  {locale}: merged new keys")


def validate():
    """Validate that all expected keys exist in the output files."""
    print("\nValidating...")
    errors = []

    # Validate emails
    for locale in EMAIL_TRANSLATIONS:
        filepath = os.path.join(TRANSLATIONS_DIR, f'emails.{locale}.yml')
        with open(filepath, 'r', encoding='utf-8') as f:
            data = yaml.safe_load(f)

        if 'unread_messages' not in data:
            errors.append(f"emails.{locale}: missing unread_messages section")
        else:
            for key in EMAIL_TRANSLATIONS[locale]['unread_messages']:
                if key not in data['unread_messages']:
                    errors.append(f"emails.{locale}: missing unread_messages.{key}")

    # Validate messages
    for locale in MESSAGES_TRANSLATIONS:
        filepath = os.path.join(TRANSLATIONS_DIR, f'messages.{locale}.yml')
        with open(filepath, 'r', encoding='utf-8') as f:
            data = yaml.safe_load(f)

        def check_keys(expected, actual, path=""):
            for key, value in expected.items():
                full_path = f"{path}.{key}" if path else key
                if key not in actual:
                    errors.append(f"messages.{locale}: missing {full_path}")
                elif isinstance(value, dict) and isinstance(actual.get(key), dict):
                    check_keys(value, actual[key], full_path)

        check_keys(MESSAGES_TRANSLATIONS[locale], data)

    if errors:
        print("  ERRORS found:")
        for e in errors:
            print(f"    - {e}")
        return False
    else:
        print("  All keys validated successfully!")
        return True


if __name__ == '__main__':
    process_emails()
    process_messages()
    success = validate()
    sys.exit(0 if success else 1)
