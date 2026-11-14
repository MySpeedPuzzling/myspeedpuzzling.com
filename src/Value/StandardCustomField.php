<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The ready-made fields (docs/features/custom-fields.md, docs/features/data-import/README.md "Ready-made fields"): an
 * ordinary custom field with a fixed key and type, so it means the same for everybody - one tap adds it, its name
 * follows the page language, the import recognises its column by header words, and a later feature can read it
 * without asking anybody to enter anything again.
 *
 * A key is stored (`custom_field.standard_key`) - never rename a case's value, never change a type.
 *
 * Not here on purpose: "Sold for". A puzzle marked as sold or swapped leaves the collections, and its item's values go
 * with the item - the field would promise to keep what the app then drops (docs/TODO.md "Custom fields").
 */
enum StandardCustomField: string
{
    case PrivateNotes = 'private_notes';
    case WhatToWorkOn = 'what_to_work_on';
    case Condition = 'condition';
    case MissingPieces = 'missing_pieces';
    case BoughtOn = 'bought_on';
    case BoughtFrom = 'bought_from';
    case PricePaid = 'price_paid';
    case WhereItIs = 'where_it_is';
    case Copies = 'copies';
    case Artist = 'artist';
    case YearOfRelease = 'year_of_release';
    case Material = 'material';
    case MyTags = 'my_tags';
    case HowILikedIt = 'how_i_liked_it';
    case HowHardItFelt = 'how_hard_it_felt';
    case Quality = 'quality';
    case StartedOn = 'started_on';

    public function type(): CustomFieldType
    {
        return match ($this) {
            self::PrivateNotes, self::WhatToWorkOn, self::BoughtFrom, self::WhereItIs, self::Artist => CustomFieldType::Text,
            self::MissingPieces, self::PricePaid, self::Copies, self::YearOfRelease => CustomFieldType::Number,
            self::BoughtOn, self::StartedOn => CustomFieldType::Date,
            self::Condition, self::Material => CustomFieldType::Choice,
            self::MyTags => CustomFieldType::Tags,
            self::HowILikedIt, self::HowHardItFelt, self::Quality => CustomFieldType::Rating,
        };
    }

    public function forResults(): bool
    {
        return match ($this) {
            self::PrivateNotes, self::WhatToWorkOn, self::MyTags, self::HowILikedIt, self::HowHardItFelt, self::StartedOn => true,
            default => false,
        };
    }

    public function forCollectionItems(): bool
    {
        return match ($this) {
            self::WhatToWorkOn, self::HowHardItFelt, self::StartedOn => false,
            default => true,
        };
    }

    /**
     * The name shown - in the page's language, never the stored one.
     */
    public function labelKey(): string
    {
        return 'my_fields.standard.' . $this->value . '.label';
    }

    /**
     * What is stored as the field's name: the English label, for anything that reads the row without a translator.
     */
    public function storedName(): string
    {
        return match ($this) {
            self::PrivateNotes => 'Private notes',
            self::WhatToWorkOn => 'What to work on',
            self::Condition => 'Condition',
            self::MissingPieces => 'Missing pieces',
            self::BoughtOn => 'Bought on',
            self::BoughtFrom => 'Bought from',
            self::PricePaid => 'Price paid',
            self::WhereItIs => 'Where it is',
            self::Copies => 'Copies',
            self::Artist => 'Artist',
            self::YearOfRelease => 'Year of release',
            self::Material => 'Material',
            self::MyTags => 'My tags',
            self::HowILikedIt => 'How I liked it',
            self::HowHardItFelt => 'How hard it felt',
            self::Quality => 'Quality',
            self::StartedOn => 'Started on',
        };
    }

    /**
     * The stored values of a ready-made "one of a list" field - keys, shown through choiceLabelKey(). The condition is
     * the sell/swap list's own (PuzzleCondition), so a listing can be prefilled from it later.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        return match ($this) {
            self::Condition => array_map(static fn (PuzzleCondition $condition): string => $condition->value, PuzzleCondition::cases()),
            self::Material => ['cardboard', 'wood', 'plastic'],
            default => [],
        };
    }

    public function choiceLabelKey(string $choice): string
    {
        return match ($this) {
            self::Condition => 'sell_swap_list.condition.' . $choice,
            default => 'my_fields.standard.' . $this->value . '.choices.' . $choice,
        };
    }

    /**
     * Column headers the import takes for this field (compared folded, whole header) - per language, the page's own
     * label is always one of them.
     *
     * @return array<string, list<string>>
     */
    public function headerWords(): array
    {
        return self::HEADER_WORDS[$this->value];
    }

    /** @var array<string, array<string, list<string>>> */
    private const array HEADER_WORDS = [
        'private_notes' => [
            'en' => ['private notes', 'private note', 'notes', 'note', 'my notes'],
            'cs' => ['soukromé poznámky', 'soukromá poznámka', 'poznámky', 'poznámka'],
            'de' => ['private notizen', 'notizen', 'notiz', 'anmerkungen'],
            'es' => ['notas privadas', 'notas', 'nota', 'observaciones'],
            'fr' => ['notes privées', 'notes', 'note', 'remarques'],
            'ja' => ['非公開メモ', 'メモ', '備考'],
        ],
        'what_to_work_on' => [
            'en' => ['what to work on', 'to work on', 'to improve', 'improve', 'lessons', 'takeaways'],
            'cs' => ['na čem zapracovat', 'co zlepšit', 'zlepšit', 'poučení'],
            'de' => ['woran arbeiten', 'verbessern', 'zu verbessern', 'erkenntnisse'],
            'es' => ['en qué trabajar', 'a mejorar', 'mejorar', 'aprendizajes'],
            'fr' => ['à travailler', 'à améliorer', 'améliorer', 'enseignements'],
            'ja' => ['取り組むこと', '改善点', '課題'],
        ],
        'condition' => [
            'en' => ['condition', 'state'],
            'cs' => ['stav'],
            'de' => ['zustand'],
            'es' => ['estado', 'condición'],
            'fr' => ['état'],
            'ja' => ['状態', 'コンディション'],
        ],
        'missing_pieces' => [
            'en' => ['missing pieces', 'missing', 'pieces missing'],
            'cs' => ['chybějící dílky', 'chybí dílků', 'chybí'],
            'de' => ['fehlende teile', 'fehlteile', 'fehlend'],
            'es' => ['piezas que faltan', 'piezas faltantes', 'faltan'],
            'fr' => ['pièces manquantes', 'manquantes'],
            'ja' => ['欠けているピース', '欠品ピース', '欠品'],
        ],
        'bought_on' => [
            'en' => ['bought on', 'purchase date', 'date bought', 'date of purchase', 'purchased'],
            'cs' => ['koupeno', 'datum nákupu', 'datum koupě'],
            'de' => ['gekauft am', 'kaufdatum'],
            'es' => ['comprado el', 'fecha de compra'],
            'fr' => ['acheté le', 'date d\'achat'],
            'ja' => ['購入日'],
        ],
        'bought_from' => [
            'en' => ['bought from', 'where from', 'purchased from', 'shop', 'store', 'source'],
            'cs' => ['koupeno od', 'koupeno kde', 'odkud', 'obchod', 'zdroj'],
            'de' => ['gekauft bei', 'woher', 'geschäft', 'laden', 'quelle'],
            'es' => ['comprado en', 'de dónde', 'tienda', 'origen'],
            'fr' => ['acheté chez', 'provenance', 'magasin', 'boutique'],
            'ja' => ['購入先', '入手先', '店'],
        ],
        'price_paid' => [
            'en' => ['price paid', 'price', 'purchase price', 'cost', 'paid'],
            'cs' => ['zaplacená cena', 'cena', 'kupní cena', 'zaplaceno'],
            'de' => ['bezahlter preis', 'preis', 'kaufpreis', 'bezahlt'],
            'es' => ['precio pagado', 'precio', 'precio de compra', 'coste'],
            'fr' => ['prix payé', 'prix', 'prix d\'achat', 'coût'],
            'ja' => ['支払った金額', '価格', '購入価格', '値段'],
        ],
        'where_it_is' => [
            'en' => ['where it is', 'location', 'place', 'stored', 'shelf', 'box'],
            'cs' => ['kde je', 'umístění', 'místo', 'uloženo', 'police'],
            'de' => ['wo es ist', 'ort', 'standort', 'lagerort', 'regal'],
            'es' => ['dónde está', 'ubicación', 'lugar', 'estante'],
            'fr' => ['où il est', 'emplacement', 'lieu', 'rangement', 'étagère'],
            'ja' => ['保管場所', '場所', '置き場所'],
        ],
        'copies' => [
            'en' => ['copies', 'quantity', 'count', 'qty'],
            'cs' => ['kusů', 'počet kusů', 'množství'],
            'de' => ['exemplare', 'anzahl', 'menge'],
            'es' => ['copias', 'ejemplares', 'cantidad'],
            'fr' => ['exemplaires', 'quantité', 'nombre'],
            'ja' => ['個数', '数量', '所持数'],
        ],
        'artist' => [
            'en' => ['artist', 'illustrator', 'author', 'designer'],
            'cs' => ['autor', 'umělec', 'ilustrátor', 'výtvarník'],
            'de' => ['künstler', 'illustrator', 'autor'],
            'es' => ['artista', 'ilustrador', 'autor'],
            'fr' => ['artiste', 'illustrateur', 'auteur'],
            'ja' => ['アーティスト', '作者', 'イラストレーター'],
        ],
        'year_of_release' => [
            'en' => ['year of release', 'release year', 'year', 'released'],
            'cs' => ['rok vydání', 'rok', 'vydáno'],
            'de' => ['erscheinungsjahr', 'jahr', 'erschienen'],
            'es' => ['año de lanzamiento', 'año de publicación', 'año'],
            'fr' => ['année de sortie', 'année de parution', 'année'],
            'ja' => ['発売年', '年'],
        ],
        'material' => [
            'en' => ['material'],
            'cs' => ['materiál'],
            'de' => ['material'],
            'es' => ['material'],
            'fr' => ['matériau', 'matière'],
            'ja' => ['素材', '材質'],
        ],
        'my_tags' => [
            'en' => ['my tags', 'tags', 'labels', 'categories', 'themes'],
            'cs' => ['moje štítky', 'štítky', 'tagy', 'kategorie', 'témata'],
            'de' => ['meine tags', 'tags', 'schlagwörter', 'kategorien', 'themen'],
            'es' => ['mis etiquetas', 'etiquetas', 'categorías', 'temas'],
            'fr' => ['mes étiquettes', 'étiquettes', 'tags', 'catégories', 'thèmes'],
            'ja' => ['マイタグ', 'タグ', 'ラベル', 'カテゴリ'],
        ],
        'how_i_liked_it' => [
            'en' => ['how i liked it', 'satisfaction', 'my rating', 'rating', 'enjoyment'],
            'cs' => ['jak se mi líbilo', 'spokojenost', 'moje hodnocení', 'hodnocení'],
            'de' => ['wie es mir gefiel', 'zufriedenheit', 'meine bewertung', 'bewertung'],
            'es' => ['cuánto me gustó', 'satisfacción', 'mi valoración', 'valoración'],
            'fr' => ['à quel point il m\'a plu', 'satisfaction', 'ma note', 'appréciation'],
            'ja' => ['気に入り度', '満足度', '評価'],
        ],
        'how_hard_it_felt' => [
            'en' => ['how hard it felt', 'difficulty', 'felt difficulty', 'perceived difficulty', 'how hard'],
            'cs' => ['jak těžké mi připadalo', 'obtížnost', 'náročnost', 'pocitová obtížnost'],
            'de' => ['wie schwer es sich anfühlte', 'schwierigkeit', 'schwierigkeitsgrad', 'gefühlte schwierigkeit'],
            'es' => ['lo difícil que me pareció', 'dificultad', 'dificultad percibida'],
            'fr' => ['difficulté ressentie', 'difficulté', 'niveau de difficulté'],
            'ja' => ['体感難易度', '難易度', '難しさ'],
        ],
        'quality' => [
            'en' => ['quality', 'puzzle quality', 'cut quality', 'print quality'],
            'cs' => ['kvalita', 'kvalita puzzle', 'kvalita výseku', 'kvalita tisku'],
            'de' => ['qualität', 'puzzlequalität', 'stanzqualität', 'druckqualität'],
            'es' => ['calidad', 'calidad del puzzle', 'calidad del corte', 'calidad de impresión'],
            'fr' => ['qualité', 'qualité du puzzle', 'qualité de découpe', 'qualité d\'impression'],
            'ja' => ['品質', 'クオリティ', 'カットの品質', '印刷の品質'],
        ],
        'started_on' => [
            'en' => ['started on', 'start date', 'date started', 'started'],
            'cs' => ['začato', 'datum zahájení', 'zahájeno'],
            'de' => ['begonnen am', 'startdatum', 'angefangen'],
            'es' => ['empezado el', 'fecha de inicio', 'comenzado'],
            'fr' => ['commencé le', 'date de début', 'commencé'],
            'ja' => ['開始日', '始めた日'],
        ],
    ];
}
