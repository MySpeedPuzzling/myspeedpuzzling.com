<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What a column of an imported sheet can be (docs/features/data-import/README.md "Sheets and columns" - the "This is"
 * list), with the header words the import takes for it in the six languages of the site.
 *
 * The values are stored with a sheet's mapping - never rename one. The order of the cases is the order equal guesses
 * come in (HeaderMatcher): the fields of a puzzle and a result first, then "MySpeedPuzzling works this out", then the
 * player's own fields.
 */
enum ImportField: string
{
    case PuzzleName = 'puzzle_name';
    case Brand = 'brand';
    case Pieces = 'pieces';
    case Barcode = 'barcode';
    case BrandCode = 'brand_code';
    case SolvedOn = 'solved_on';
    case Time = 'time';
    case SolvedWith = 'solved_with';
    /** Solo / pair / team */
    case SolvingType = 'solving_type';
    case FirstTry = 'first_try';
    /** 1 = the first try */
    case AttemptNumber = 'attempt_number';
    case SolvedWithoutBox = 'solved_without_box';
    case AlreadyOnMySpeedPuzzling = 'already_on_myspeedpuzzling';
    case Owned = 'owned';
    /** Sold, returned, given away: a yes means the puzzle is not the player's any more */
    case NotOwned = 'not_owned';
    /** Decides what a row is: completed / waiting / wish list */
    case Status = 'status';
    case PublicComment = 'public_comment';
    case Collection = 'collection';
    /** A column of formulas MySpeedPuzzling works out itself (pieces per minute, place, percentages) - "Don't import" */
    case Computed = 'computed';
    /** One of the ready-made fields (StandardCustomField) - which one is in the guess */
    case ReadyMadeField = 'ready_made_field';

    /**
     * Column headers the import takes for this field, per language - compared folded, without punctuation
     * (HeaderMatcher). The ready-made fields carry their own (StandardCustomField::headerWords()).
     *
     * @return array<string, list<string>>
     */
    public function headerWords(): array
    {
        return self::HEADER_WORDS[$this->value] ?? [];
    }

    /** @var array<string, array<string, list<string>>> */
    private const array HEADER_WORDS = [
        'puzzle_name' => [
            'en' => ['title', 'name', 'puzzle', 'puzzle name', 'puzzle title', 'name of puzzle', 'image', 'image name', 'motif'],
            'cs' => ['název', 'titul', 'jméno', 'název puzzle', 'puzzle', 'motiv', 'obrázek'],
            'de' => ['titel', 'name', 'puzzle', 'puzzlename', 'puzzletitel', 'motiv', 'bezeichnung'],
            'es' => ['nombre', 'título', 'puzzle', 'rompecabezas', 'nombre del puzzle', 'imagen'],
            'fr' => ['titre', 'nom', 'puzzle', 'nom du puzzle', 'image'],
            'ja' => ['タイトル', '名前', 'パズル名', 'パズル', '作品名', '商品名'],
        ],
        'brand' => [
            'en' => ['brand', 'manufacturer', 'maker', 'make', 'brand name', 'puzzle brand', 'company', 'publisher', 'producer'],
            'cs' => ['výrobce', 'značka', 'firma'],
            'de' => ['hersteller', 'marke', 'verlag', 'firma'],
            'es' => ['marca', 'fabricante', 'editorial'],
            'fr' => ['marque', 'fabricant', 'éditeur'],
            'ja' => ['メーカー', 'ブランド', '製造元'],
        ],
        'pieces' => [
            'en' => ['pieces', 'pcs', 'pc', 'piece count', 'pieces count', 'number of pieces', 'no of pieces', 'nr of pieces', 'num pieces', 'of pieces', 'number pieces', 'number of elements', 'elements', 'size', 'puzzle size'],
            'cs' => ['dílků', 'počet dílků', 'dílky', 'počet dílů', 'dílů', 'velikost'],
            'de' => ['teile', 'teilezahl', 'teileanzahl', 'anzahl teile', 'anzahl der teile', 'größe'],
            'es' => ['piezas', 'número de piezas', 'nº de piezas', 'n de piezas', 'cantidad de piezas', 'tamaño'],
            'fr' => ['pièces', 'nombre de pièces', 'nb de pièces', 'nb pièces', 'taille'],
            'ja' => ['ピース', 'ピース数', 'サイズ'],
        ],
        'barcode' => [
            'en' => ['ean', 'ean13', 'ean 13', 'ean code', 'barcode', 'bar code', 'upc', 'upc code', 'gtin'],
            'cs' => ['čárový kód', 'ean kód', 'ean'],
            'de' => ['strichcode', 'barcode', 'ean', 'ean code'],
            'es' => ['código de barras', 'ean'],
            'fr' => ['code-barres', 'code barres', 'code à barres', 'ean'],
            'ja' => ['バーコード', 'janコード', 'jan'],
        ],
        'brand_code' => [
            'en' => ['brand code', 'item number', 'item no', 'item code', 'article number', 'article no', 'art no', 'product code', 'product number', 'sku', 'model number', 'model', 'catalogue number', 'catalog number', 'reference', 'ref', 'code'],
            'cs' => ['kód výrobce', 'kód', 'katalogové číslo', 'číslo výrobku', 'obj. číslo', 'objednací číslo'],
            'de' => ['artikelnummer', 'art.-nr.', 'artikelnr', 'bestellnummer', 'herstellernummer', 'produktnummer'],
            'es' => ['código del fabricante', 'referencia', 'código', 'número de artículo'],
            'fr' => ['référence', 'réf', 'code fabricant', 'numéro d\'article', 'code'],
            'ja' => ['品番', '型番', '商品番号'],
        ],
        'solved_on' => [
            'en' => ['date', 'completed', 'finished', 'solved', 'done', 'end date', 'date completed', 'date finished', 'date solved', 'completion date', 'finish date', 'completed on', 'finished on', 'solved on', 'date of completion', 'when'],
            'cs' => ['datum', 'dokončeno', 'složeno', 'datum dokončení', 'datum složení', 'hotovo', 'kdy'],
            'de' => ['datum', 'fertig', 'fertiggestellt', 'abgeschlossen', 'gelegt', 'enddatum', 'fertig am', 'gelegt am', 'wann'],
            'es' => ['fecha', 'terminado', 'completado', 'fecha de finalización', 'fecha de fin', 'fecha final', 'cuándo'],
            'fr' => ['date', 'terminé', 'fini', 'date de fin', 'terminé le', 'fini le', 'quand'],
            'ja' => ['日付', '完成日', '完成', '完了日'],
        ],
        'time' => [
            'en' => ['time', 'duration', 'solve time', 'solving time', 'completion time', 'time taken', 'total time', 'finish time', 'my time', 'time to complete', 'time spent'],
            'cs' => ['čas', 'doba', 'čas složení', 'výsledný čas', 'doba skládání'],
            'de' => ['zeit', 'dauer', 'puzzlezeit', 'gesamtzeit', 'benötigte zeit'],
            'es' => ['tiempo', 'duración', 'tiempo total', 'tiempo de resolución'],
            'fr' => ['temps', 'durée', 'temps total', 'temps de réalisation'],
            'ja' => ['タイム', '時間', '所要時間', '完成時間'],
        ],
        'solved_with' => [
            'en' => ['solved with', 'with', 'with whom', 'partner', 'partners', 'teammates', 'team', 'team members', 'co-puzzlers', 'puzzled with', 'done with', 'who'],
            'cs' => ['s kým', 'spoluhráči', 'tým', 'partner', 'parťák', 'skládáno s'],
            'de' => ['mit', 'mit wem', 'partner', 'team', 'mitpuzzler', 'gepuzzelt mit'],
            'es' => ['con', 'con quién', 'compañeros', 'equipo', 'pareja'],
            'fr' => ['avec', 'avec qui', 'partenaire', 'partenaires', 'équipe', 'coéquipiers'],
            'ja' => ['一緒に', 'パートナー', 'チーム', 'メンバー'],
        ],
        'solving_type' => [
            'en' => ['solo/pair/team', 'solo or team', 'solo/team', 'solving type', 'puzzling type', 'mode', 'players', 'puzzlers', 'people', 'number of people', 'number people', 'number of puzzlers', 'team size', 'participants'],
            'cs' => ['typ skládání', 'sólo/dvojice/tým', 'počet lidí', 'počet hráčů', 'jednotlivec/dvojice/tým'],
            'de' => ['modus', 'solo/paar/team', 'anzahl personen', 'personen', 'einzel/paar/team'],
            'es' => ['modalidad', 'individual/pareja/equipo', 'número de personas', 'personas'],
            'fr' => ['mode', 'solo/duo/équipe', 'nombre de personnes', 'personnes'],
            'ja' => ['人数', 'ソロ/ペア/チーム', '種別'],
        ],
        'first_try' => [
            'en' => ['first try', 'first attempt', 'first time', '1st try', '1st attempt', '1st time', 'first solve', 'first go'],
            'cs' => ['první pokus', 'poprvé', 'první skládání'],
            'de' => ['erster versuch', 'erstversuch', 'erstes mal', 'zum ersten mal'],
            'es' => ['primer intento', 'primera vez'],
            'fr' => ['premier essai', 'première fois', 'première tentative'],
            'ja' => ['初回', '初挑戦', '初めて'],
        ],
        'attempt_number' => [
            'en' => ['attempt', 'attempt number', 'attempt no', 'attempt nr', 'attempts', 'try', 'try number', 'nth attempt', 'solve number', 'run'],
            'cs' => ['pokus', 'číslo pokusu', 'kolikátý pokus', 'pořadí pokusu', 'pokus č.'],
            'de' => ['versuch', 'versuchsnummer', 'versuch nr.', 'durchgang'],
            'es' => ['intento', 'número de intento', 'nº de intento'],
            'fr' => ['essai', 'tentative', 'numéro d\'essai', 'n° d\'essai'],
            'ja' => ['回数', '何回目', '挑戦回数'],
        ],
        'solved_without_box' => [
            'en' => ['no box', 'without box', 'without the box', 'solved without the box', 'no picture', 'without picture', 'no image', 'without image', 'no reference', 'boxless', 'blind'],
            'cs' => ['bez krabice', 'bez předlohy', 'bez obrázku', 'naslepo'],
            'de' => ['ohne schachtel', 'ohne vorlage', 'ohne bild', 'ohne deckel', 'blind'],
            'es' => ['sin caja', 'sin imagen', 'sin referencia', 'a ciegas'],
            'fr' => ['sans boîte', 'sans modèle', 'sans image', 'à l\'aveugle'],
            'ja' => ['箱なし', '見本なし', '箱絵なし'],
        ],
        'already_on_myspeedpuzzling' => [
            'en' => ['entered on msp', 'on msp', 'in msp', 'msp', 'added to msp', 'logged on msp', 'logged in msp', 'entered in msp', 'uploaded to msp', 'myspeedpuzzling', 'on myspeedpuzzling', 'entered on myspeedpuzzling', 'my speed puzzling', 'on my speed puzzling', 'entered on my speed puzzling', 'logged', 'entered', 'uploaded'],
            'cs' => ['na msp', 'zadáno na msp', 'zapsáno na msp', 'zadáno', 'zapsáno'],
            'de' => ['auf msp', 'bei msp eingetragen', 'in msp eingetragen', 'eingetragen'],
            'es' => ['en msp', 'registrado en msp', 'registrado'],
            'fr' => ['sur msp', 'saisi sur msp', 'enregistré sur msp', 'enregistré'],
            'ja' => ['msp登録済み', '登録済み', 'msp登録'],
        ],
        'owned' => [
            'en' => ['owned', 'own', 'i own it', 'own it', 'do i own it', 'in collection', 'in my collection', 'have', 'have it', 'still have', 'still own', 'kept', 'keep'],
            'cs' => ['vlastním', 'mám', 'mám doma', 've sbírce', 'vlastněno'],
            'de' => ['besitze', 'im besitz', 'vorhanden', 'habe ich', 'in sammlung'],
            'es' => ['tengo', 'lo tengo', 'en mi colección', 'en propiedad'],
            'fr' => ['possédé', 'je l\'ai', 'dans ma collection', 'en ma possession'],
            'ja' => ['所有', '所持', '持っている'],
        ],
        'not_owned' => [
            'en' => ['not owned', 'no longer own', 'no longer owned', 'got rid of', 'gone', 'sold', 'given away', 'gave away', 'donated', 'returned', 'traded', 'swapped', 'passed on'],
            'cs' => ['nevlastním', 'už nemám', 'prodáno', 'darováno', 'vráceno', 'pryč'],
            'de' => ['nicht mehr vorhanden', 'verkauft', 'verschenkt', 'abgegeben', 'weg'],
            'es' => ['ya no lo tengo', 'vendido', 'regalado', 'devuelto'],
            'fr' => ['plus en ma possession', 'vendu', 'donné', 'rendu'],
            'ja' => ['手放した', '売却', '売却済み', '譲渡'],
        ],
        'status' => [
            'en' => ['status', 'stage', 'progress'],
            'cs' => ['stav', 'status'],
            'de' => ['status', 'stand'],
            'es' => ['estado', 'estatus'],
            'fr' => ['statut', 'état d\'avancement'],
            'ja' => ['状態', 'ステータス'],
        ],
        'public_comment' => [
            'en' => ['public comment', 'public comments', 'public note', 'public notes', 'comment for everyone', 'msp comment'],
            'cs' => ['veřejný komentář', 'veřejná poznámka'],
            'de' => ['öffentlicher kommentar', 'öffentliche notiz'],
            'es' => ['comentario público', 'nota pública'],
            'fr' => ['commentaire public', 'note publique'],
            'ja' => ['公開コメント'],
        ],
        'collection' => [
            'en' => ['collection', 'list', 'pile', 'group', 'folder'],
            'cs' => ['sbírka', 'kolekce', 'seznam'],
            'de' => ['sammlung', 'liste', 'stapel'],
            'es' => ['colección', 'lista'],
            'fr' => ['collection', 'liste', 'pile'],
            'ja' => ['コレクション', 'リスト'],
        ],
        'computed' => [
            'en' => ['ppm', 'pieces per minute', 'pieces/minute', 'pieces per min', 'pieces a minute', 'pph', 'pieces per hour', 'pace', 'speed', 'seconds per piece', 'time per piece', 'diff', 'difference', 'percentage', 'percent', 'percentile', 'in top percentage', 'top percentage', 'standing', 'current standing', 'place', 'placing', 'placement', 'rank', 'ranking', 'position', 'days to complete', 'average', 'avg'],
            'cs' => ['dílků za minutu', 'rychlost', 'tempo', 'pořadí', 'umístění', 'průměr', 'rozdíl', 'procento', 'procenta'],
            'de' => ['teile pro minute', 'geschwindigkeit', 'tempo', 'platz', 'platzierung', 'rang', 'durchschnitt', 'differenz', 'prozent'],
            'es' => ['piezas por minuto', 'velocidad', 'ritmo', 'posición', 'puesto', 'promedio', 'media', 'diferencia', 'porcentaje'],
            'fr' => ['pièces par minute', 'vitesse', 'rythme', 'classement', 'rang', 'moyenne', 'différence', 'pourcentage'],
            'ja' => ['1分あたりのピース数', '速度', 'ペース', '順位', '平均', '差', '割合'],
        ],
    ];
}
