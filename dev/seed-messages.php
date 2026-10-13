<?php
/**
 * Lokálny testovací web: vymaže všetky správy Flamingo a založí vymyslené.
 *
 * Najprv treba spustiť setup-forms.php. Potom v priečinku lokálneho WordPressu:
 *   wp eval-file "~/Desktop/MESSAGE EXPORT FOR FLAMINGO/dev/seed-messages.php"
 *   wp eval-file "~/Desktop/MESSAGE EXPORT FOR FLAMINGO/dev/seed-messages.php" bulk=5000
 *
 * Bez parametra vznikne asi 120 správ z dvoch formulárov za október 2025 až
 * október 2026. Väčšina sú bežné správy, k tomu krajné prípady, na ktorých sa
 * export môže pokaziť (popis pri každom). Parameter bulk=N pridá N náhodných
 * správ od januára 2024 na test veľkých exportov (pamäť, časový limit).
 *
 * Správy sa ukladajú cez Flamingo_Inbound_Message::add() s rovnakými údajmi,
 * aké posiela Contact Form 7 (modules/flamingo.php, verzia 6.2.2), len
 * s vlastným časom odoslania. Generátor má pevné semienko, takže každé
 * spustenie dá rovnaké dáta. Všetky mená, adresy a čísla sú vymyslené,
 * e-maily sú na rezervovaných doménach example.com/org/net a IP adresy
 * z rozsahov pre dokumentáciu.
 *
 * Súbor nepatrí do pluginu, priečinok dev/ musí byť v .distignore.
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Len pre lokálny web (WP_ENVIRONMENT_TYPE = local) cez WP-CLI.\n";
	return;
}

if ( ! class_exists( 'Flamingo_Inbound_Message' ) || ! function_exists( 'wpcf7_flamingo_add_channel' ) ) {
	WP_CLI::error( 'Contact Form 7 a Flamingo musia byť aktívne.' );
}

$mef_dev_bulk = 0;
foreach ( (array) ( $args ?? array() ) as $mef_dev_arg ) {
	if ( preg_match( '/^bulk=(\d+)$/', $mef_dev_arg, $mef_dev_m ) ) {
		$mef_dev_bulk = (int) $mef_dev_m[1];
	}
}

mt_srand( 20261013 );

/*
 * Formuláre a ich kanály vo Flamingu.
 */

$mef_dev_forms = array();
foreach ( array( 'kontakt', 'prihlaska-na-kurz' ) as $mef_dev_slug ) {
	$mef_dev_post = get_page_by_path( $mef_dev_slug, OBJECT, 'wpcf7_contact_form' );
	if ( ! $mef_dev_post ) {
		WP_CLI::error( "Chýba formulár $mef_dev_slug, najprv spusti setup-forms.php." );
	}
	$mef_dev_form = wpcf7_contact_form( $mef_dev_post->ID );

	// Rovnako ako wpcf7_flamingo_submit(): kanál podľa formulára, ID kanála do _flamingo.
	$mef_dev_flamingo = (array) get_post_meta( $mef_dev_form->id(), '_flamingo', true );
	$mef_dev_channel  = isset( $mef_dev_flamingo['channel'] )
		? (int) $mef_dev_flamingo['channel']
		: wpcf7_flamingo_add_channel( $mef_dev_form->name(), $mef_dev_form->title() );
	update_post_meta( $mef_dev_form->id(), '_flamingo', array_merge( $mef_dev_flamingo, array( 'channel' => $mef_dev_channel ) ) );

	$mef_dev_forms[ $mef_dev_slug ] = array(
		'id'      => $mef_dev_form->id(),
		'channel' => get_term( $mef_dev_channel, Flamingo_Inbound_Message::channel_taxonomy )->slug,
		'consent' => 'prihlaska-na-kurz' === $mef_dev_slug
			? array( 'suhlas' => 'Súhlasím so spracovaním osobných údajov na účely prihlášky.' )
			: array(),
	);
}

// Formulár zmazaný v CF7: kanál vo Flamingu aj správy ostali, formulár nie.
// CF7 by kanál založil rovnako, pod "contact-form-7" so slugom formulára.
$mef_dev_forms['objednavka-katalogu'] = array(
	'id'      => 0,
	'channel' => get_term( wpcf7_flamingo_add_channel( 'objednavka-katalogu', 'Objednávka katalógu' ), Flamingo_Inbound_Message::channel_taxonomy )->slug,
	'consent' => array(),
);

$mef_dev_page = get_page_by_path( 'formulare' );

/*
 * Pomocné funkcie.
 */

/** Unix čas z miestneho času webu, napr. '2026-01-31 23:47'. */
function mef_dev_ts( $local ) {
	return ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
}

/** Náhodný čas medzi dvoma miestnymi dátumami, prevažne cez deň. */
function mef_dev_random_ts( $from, $to ) {
	$ts   = mt_rand( mef_dev_ts( $from ), mef_dev_ts( $to ) );
	$hour = (int) wp_date( 'G', $ts );
	if ( $hour < 7 && mt_rand( 0, 9 ) > 0 ) {
		$ts += 8 * HOUR_IN_SECONDS;
	}
	return $ts;
}

function mef_dev_pick( array $items ) {
	return $items[ mt_rand( 0, count( $items ) - 1 ) ];
}

/** Vymyslená osoba: meno, priezvisko a e-mail v niektorom bežnom tvare. */
function mef_dev_person( $female = null ) {
	$female = null === $female ? (bool) mt_rand( 0, 1 ) : $female;
	$first  = $female
		? mef_dev_pick( array( 'Jana', 'Mária', 'Eva', 'Zuzana', 'Katarína', 'Lucia', 'Martina', 'Veronika', 'Ivana', 'Petra', 'Andrea', 'Monika', 'Simona', 'Barbora', 'Lenka', 'Dominika', 'Michaela', 'Silvia', 'Ľubica', 'Žaneta' ) )
		: mef_dev_pick( array( 'Peter', 'Martin', 'Ján', 'Tomáš', 'Michal', 'Lukáš', 'Marek', 'Ľubomír', 'Štefan', 'Pavol', 'Jozef', 'Róbert', 'Dušan', 'Matej', 'Ondrej', 'Erik', 'Šimon', 'Andrej', 'Miroslav', 'Igor' ) );
	$pair   = mef_dev_pick(
		array(
			array( 'Kováč', 'Kováčová' ),
			array( 'Horváth', 'Horváthová' ),
			array( 'Varga', 'Vargová' ),
			array( 'Tóth', 'Tóthová' ),
			array( 'Baláž', 'Balážová' ),
			array( 'Molnár', 'Molnárová' ),
			array( 'Lukáč', 'Lukáčová' ),
			array( 'Novák', 'Nováková' ),
			array( 'Šimko', 'Šimková' ),
			array( 'Kollár', 'Kollárová' ),
			array( 'Polák', 'Poláková' ),
			array( 'Krajčír', 'Krajčírová' ),
			array( 'Hudák', 'Hudáková' ),
			array( 'Ďurica', 'Ďuricová' ),
			array( 'Čierny', 'Čierna' ),
			array( 'Mráz', 'Mrázová' ),
			array( 'Žiak', 'Žiaková' ),
			array( 'Bielik', 'Bieliková' ),
			array( 'Ondruš', 'Ondrušová' ),
			array( 'Gašpar', 'Gašparová' ),
		)
	);
	$last   = $pair[ $female ? 1 : 0 ];

	return array(
		'name'     => "$first $last",
		'email'    => mef_dev_email( $first, $last ),
		'surnames' => $pair,
	);
}

function mef_dev_email( $first, $last ) {
	$f = strtolower( remove_accents( $first ) );
	$l = strtolower( remove_accents( $last ) );
	switch ( mt_rand( 0, 3 ) ) {
		case 0:
			$local = "$f.$l";
			break;
		case 1:
			$local = $f[0] . $l;
			break;
		case 2:
			$local = "$l.$f";
			break;
		default:
			$local = $f . $l . mt_rand( 10, 99 );
	}
	return $local . '@' . mef_dev_pick( array( 'example.com', 'example.com', 'example.org', 'example.net' ) );
}

/** Telefón v rôznych tvaroch, ako ich ľudia píšu. Predvoľba 0999 je vymyslená. */
function mef_dev_phone() {
	$n = sprintf( '%03d%03d', mt_rand( 0, 999 ), mt_rand( 0, 999 ) );
	$a = substr( $n, 0, 3 );
	$b = substr( $n, 3, 3 );
	return mef_dev_pick(
		array(
			"0999 $a $b",
			"0999 $a $b",
			"0999$a$b",
			"+421 999 $a $b",
			"+421999$a$b",
			"+421 (0)999 $a $b",
		)
	);
}

function mef_dev_ip() {
	return mef_dev_pick(
		array(
			'192.0.2.' . mt_rand( 1, 254 ),
			'198.51.100.' . mt_rand( 1, 254 ),
			'203.0.113.' . mt_rand( 1, 254 ),
			'2001:db8::' . dechex( mt_rand( 1, 65535 ) ),
		)
	);
}

function mef_dev_user_agent() {
	return mef_dev_pick(
		array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Safari/605.1.15',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Mobile/15E148 Safari/604.1',
			'Mozilla/5.0 (Linux; Android 16; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:143.0) Gecko/20100101 Firefox/143.0',
		)
	);
}

/*
 * Bežné správy z formulára Kontakt.
 * Pole your-phone pribudlo do formulára 1. 3. 2026, staršie správy ho nemajú vôbec.
 */

function mef_dev_kontakt( $ts, $person = null, $subject = null, $message = null ) {
	$person = $person ?: mef_dev_person();

	if ( null === $subject ) {
		list( $subject, $message ) = mef_dev_pick(
			array(
				array( 'Otváracie hodiny počas sviatkov', "Dobrý deň,\nbudete mať otvorené aj medzi sviatkami? Ďakujem za odpoveď." ),
				array( 'Cenová ponuka', "Dobrý deň,\nprosím o cenovú ponuku na skupinu 12 osôb, ideálne v novembri. Stačí orientačne.\n\nS pozdravom\n%name%" ),
				array( 'Faktúra', "Dobrý deň, na poslednej faktúre je uvedená stará adresa. Môžete mi poslať opravenú? Ďakujem, %name%" ),
				array( 'Ponuka spolupráce', "Dobrý deň,\nsme malá firma z Trnavy a radi by sme vám ponúkli spoluprácu pri propagácii. Kedy by sa vám hodil krátky hovor?\n\n%name%" ),
				array( 'Reklamácia', "Dobrý deň, objednávka prišla neúplná, chýba jeden kus. Prosím o riešenie." ),
				array( 'Otázka k termínom', 'Kedy začína najbližší kurz pre dospelých? Ďakujem.' ),
				array( 'Zmena kontaktných údajov', "Dobrý deň,\nzmenilo sa mi telefónne číslo, nové nájdete v tomto formulári.\nĎakujem" ),
				array( 'Poďakovanie', 'Chcela by som sa poďakovať za ochotu a rýchle vybavenie. Určite sa ozveme znova.' ),
				array( 'Parkovanie', 'Dá sa pri vás niekde zaparkovať, alebo je lepšie prísť MHD?' ),
				array( 'Zrušenie objednávky', "Dobrý deň,\nprosím o zrušenie mojej objednávky z minulého týždňa, už ju nepotrebujem.\n\nS pozdravom %name%" ),
				array( 'Darčekový poukaz', 'Predávate aj darčekové poukazy? Ak áno, na akú sumu a dajú sa poslať e-mailom?' ),
				array( 'Prístup pre vozíčkarov', 'Je budova bezbariérová? Ide s nami starý otec na vozíku.' ),
			)
		);
	}
	$message = str_replace( '%name%', $person['name'], (string) $message );

	$fields = array(
		'your-name'    => $person['name'],
		'your-email'   => $person['email'],
		'your-phone'   => mt_rand( 0, 2 ) ? '' : mef_dev_phone(),
		'your-subject' => $subject,
		'your-message' => $message,
	);
	if ( $ts < mef_dev_ts( '2026-03-01 00:00' ) ) {
		unset( $fields['your-phone'] );
	}

	return array(
		'form'   => 'kontakt',
		'ts'     => $ts,
		'fields' => $fields,
	);
}

/*
 * Bežné prihlášky z formulára Prihláška na kurz.
 * Pole skola pribudlo 1. 6. 2026, staršie prihlášky ho nemajú vôbec.
 * Nezaškrtnuté dni CF7 uloží ako prázdne pole a presunie ich na koniec,
 * rovnako ako nevyplnené polia, ktoré prehliadač vôbec neposlal.
 */

function mef_dev_prihlaska( $ts, $parent = null, $overrides = array() ) {
	$parent   = $parent ?: mef_dev_person();
	$surnames = $parent['surnames'] ?? array( 'Novák', 'Nováková' );
	$girl     = (bool) mt_rand( 0, 1 );
	$child    = ( $girl
		? mef_dev_pick( array( 'Ema', 'Sofia', 'Nina', 'Viktória', 'Natália', 'Laura', 'Hana', 'Lea', 'Ella', 'Tamara' ) )
		: mef_dev_pick( array( 'Jakub', 'Samuel', 'Adam', 'Filip', 'Oliver', 'Matúš', 'Tobias', 'Alex', 'Hugo', 'Leo' ) )
	) . ' ' . $surnames[ $girl ? 1 : 0 ];

	$days = array( 'Pondelok', 'Utorok', 'Streda', 'Štvrtok', 'Piatok' );
	shuffle( $days );
	$days = array_slice( $days, 0, mt_rand( 1, 3 ) );
	$days = array_values( array_intersect( array( 'Pondelok', 'Utorok', 'Streda', 'Štvrtok', 'Piatok' ), $days ) );

	$fields = array(
		'meno-dietata'    => $child,
		'datum-narodenia' => sprintf( '%04d-%02d-%02d', mt_rand( 2012, 2020 ), mt_rand( 1, 12 ), mt_rand( 1, 28 ) ),
		'skola'           => mt_rand( 0, 2 ) ? mef_dev_pick( array( 'ZŠ Hlavná 1', 'ZŠ s MŠ Lipová', 'Gymnázium Jána Hollého', 'ZŠ Komenského', 'Súkromná ZŠ Slniečko' ) ) : '',
		'meno-rodica'     => $parent['name'],
		'email'           => $parent['email'],
		'telefon'         => mef_dev_phone(),
		'kurz'            => array( mef_dev_pick( array( 'Angličtina pre začiatočníkov', 'Keramika', 'Fotografia', 'Programovanie pre deti', 'Šach' ) ) ),
		'dni'             => $days,
		'skusenosti'      => array( mt_rand( 0, 2 ) ? 'Nie' : 'Áno' ),
		'poznamka'        => mt_rand( 0, 2 ) ? '' : mef_dev_pick(
			array(
				'Syn má alergiu na orechy.',
				'Dcéra by chcela chodiť spolu s kamarátkou z triedy.',
				'Môžeme začať až od novembra?',
				'Prosím o informáciu o cene a o možnosti platby na dve splátky.',
				'Ak by sa dalo, preferujeme poobedie po 15:00.',
				"Dobrý deň,\nmáme záujem aj o letný tábor.\nĎakujem",
				'Súrodenec už u vás chodí, platí súrodenecká zľava?',
			)
		),
		'suhlas'          => array( '1' ),
	);

	foreach ( $overrides as $key => $value ) {
		$fields[ $key ] = $value;
	}

	if ( $ts < mef_dev_ts( '2026-06-01 00:00' ) ) {
		unset( $fields['skola'] );
	}
	if ( array() === $fields['dni'] ) {
		unset( $fields['dni'] );
		$fields['dni'] = array();
	}

	return array(
		'form'   => 'prihlaska-na-kurz',
		'ts'     => $ts,
		'fields' => $fields,
	);
}

/*
 * Objednávky z formulára, ktorý bol neskôr zmazaný. Bez definície formulára
 * sa názvy stĺpcov dajú vziať len z názvov polí.
 */

function mef_dev_objednavka( $ts ) {
	$person = mef_dev_person();

	return array(
		'form'   => 'objednavka-katalogu',
		'ts'     => $ts,
		'fields' => array(
			'meno'   => $person['name'],
			'email'  => $person['email'],
			'adresa' => mef_dev_pick( array( 'Hlavná 5', 'Lipová 12', 'Školská 3' ) ) . "\n" . mef_dev_pick( array( '811 01 Bratislava', '917 01 Trnava', '949 01 Nitra' ) ),
			'pocet'  => (string) mt_rand( 1, 3 ),
		),
	);
}

/*
 * Zoznam správ: najprv krajné prípady s presným časom, potom bežné náhodné.
 */

$mef_dev_items = array();

// Kontakt: noc pri zmene letného času na zimný, 2:30 nastane dvakrát.
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2025-10-26 02:30' ), null, 'Otázka k termínom', 'Píšem v noci, lebo cez deň nestíham. Kedy máte voľný termín?' );

// Kontakt: tesne pred polnocou a tesne po nej na prelome mesiaca. Po polnoci
// je v UTC ešte predchádzajúci deň, filter dátumu musí rátať s časom webu.
$mef_dev_jana    = array(
	'name'  => 'Jana Kováčová',
	'email' => 'jana.kovacova@example.com',
);
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-01-31 23:47' ), $mef_dev_jana, 'Faktúra č. 2026/0031', "Dobrý deň,\nposielam opravenú adresu: Hlavná 12; 811 01 Bratislava.\nCena 25,50 €; splatnosť \"do konca mesiaca\", t. j. 15. 2. 2026.\n\nJana Kováčová" );
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-02-01 00:05' ), $mef_dev_jana, 'Doplnenie k faktúre', 'Ešte IČO: 12345678, DIČ 2020123456. Ďakujem.' );

// Kontakt: ten istý e-mail s veľkými písmenami, filter e-mailu nemá rozlišovať.
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-05-14 09:12' ), array( 'name' => 'Jana Kováčová', 'email' => 'Jana.Kovacova@Example.COM' ), 'Opäť ja', 'Dobrý deň, píšem z mobilu, opravila sa vám tá faktúra?' );

// Kontakt: prvá noc letného času, 2:00 až 3:00 neexistuje.
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-03-29 03:15' ), null, 'Nočná otázka', 'Je ešte voľné miesto na sobotu?' );

// Kontakt: dlhá správa s odsekmi (bunka nad 2 000 znakov).
$mef_dev_items[] = mef_dev_kontakt(
	mef_dev_ts( '2026-04-08 21:33' ),
	null,
	'Reklamácia - podrobný popis',
	implode(
		"\n\n",
		array_fill(
			0,
			8,
			'Dobrý deň, objednávku sme prevzali v poriadku, ale po rozbalení sme zistili, že časť tovaru nezodpovedá popisu na webe. Farba je výrazne tmavšia, rozmery sa líšia o niekoľko centimetrov a návod chýba úplne. Prosím o vyjadrenie, či je možné tovar vymeniť alebo vrátiť peniaze, a akým spôsobom ho máme poslať späť. Fotografie vieme poslať e-mailom.'
		)
	)
);

// Kontakt: hodnoty, ktoré Excel môže brať ako vzorec (=, +, -, @ na začiatku).
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-06-02 10:20' ), null, '-20 % zľava pre stálych zákazníkov?', 'Platí ešte zľava -20 % z letáku?' );
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-06-03 11:45' ), null, '+421 číslo nefunguje', '+421 číslo z vášho webu nefunguje, skúšal som niekoľkokrát.' );
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-06-04 14:02' ), null, '@info adresa sa vracia', '@info adresa mi vracia správy späť ako nedoručiteľné.' );
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-06-05 08:30' ), null, '=1+1', '=SUM(A1:A3) toto mi vypísalo vaše počítadlo na webe.' );

// Kontakt: emoji (4-bajtové UTF-8).
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-07-11 16:40' ), null, 'Ďakujeme', 'Ďakujem za rýchlu odpoveď 🙂 Všetko vybavené.' );

// Kontakt: tabulátor a zvislý tabulátor z kopírovania z Wordu. Zvislý
// tabulátor je v XML 1.0 zakázaný znak, nevyčistený pokazí súbor .xlsx.
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-07-20 13:13' ), null, 'Text z Wordu', "Položka\tCena\tKusy\nStolička\t45 €\t4\x0BPoznámka skopírovaná z Wordu." );

// Kontakt: znaky so zvláštnym významom v HTML a XML.
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-08-05 09:05' ), null, 'Súrne <b>dôležité</b>', '<b>Dôležité</b> & súrne: 5 < 10 > 3, "úvodzovky" a \'apostrofy\'.' );

// Kontakt: apostrof v mene, anglický text, dlhá URL bez medzier.
$mef_dev_items[] = mef_dev_kontakt( mef_dev_ts( '2026-08-19 18:22' ), array( 'name' => "Seán O'Connor", 'email' => 'sean.oconnor@example.org' ), 'Booking question', 'Hello, is this still available? https://www.example.com/kurzy/2026/jesen/detail?id=123456&utm_source=newsletter&utm_medium=email&utm_campaign=jesen-2026-velmi-dlhy-nazov-kampane' );

// Kontakt: e-mail sa neodoslal (mail_failed), Flamingo správu uloží, kontakt nie.
$mef_dev_failed   = mef_dev_kontakt( mef_dev_ts( '2026-09-10 12:00' ) );
$mef_dev_failed['status'] = 'mail_failed';
$mef_dev_items[]  = $mef_dev_failed;

// Kontakt: správa v koši.
$mef_dev_trashed          = mef_dev_kontakt( mef_dev_ts( '2026-09-15 15:15' ), null, 'Test', 'Skúška formulára, prosím ignorovať.' );
$mef_dev_trashed['trash'] = true;
$mef_dev_items[]          = $mef_dev_trashed;

// Kontakt: spam.
foreach (
	array(
		array( '2026-02-17 03:12', array( 'name' => 'SEO Expert', 'email' => 'seo@example.net' ), 'SEO services for your website', "Hi,\nI checked your website and found 37 errors. We can bring you to the first page of Google.\nhttps://seo.example.net/offer\nhttps://seo.example.net/prices" ),
		array( '2026-05-30 04:44', array( 'name' => 'Klik', 'email' => 'x@example.net' ), '=HYPERLINK("http://example.net/x","Kliknite sem")', '=HYPERLINK("http://example.net/x","Kliknite sem")' ),
		array( '2026-09-21 02:01', array( 'name' => 'Crypto Bot', 'email' => 'invest@example.net' ), 'Earn 5000 € per week 🚀', 'Invest now 🚀🚀🚀 https://crypto.example.net/?ref=123' ),
	) as $mef_dev_spam
) {
	$mef_dev_item             = mef_dev_kontakt( mef_dev_ts( $mef_dev_spam[0] ), $mef_dev_spam[1], $mef_dev_spam[2], $mef_dev_spam[3] );
	$mef_dev_item['status']   = 'spam';
	$mef_dev_item['spam_log'] = array(
		array(
			'agent'  => 'disallowed_list',
			'reason' => 'Disallowed words (http) are used.',
		),
	);
	$mef_dev_items[]          = $mef_dev_item;
}

// Prihláška: rodič prihlasuje dve deti krátko po sebe, rovnaký e-mail.
$mef_dev_parent  = array(
	'name'     => 'Martina Hudáková',
	'email'    => 'martina.hudakova@example.com',
	'surnames' => array( 'Hudák', 'Hudáková' ),
);
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-08-20 18:02' ), $mef_dev_parent, array( 'meno-dietata' => 'Ema Hudáková', 'kurz' => array( 'Keramika' ) ) );
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-08-20 18:09' ), $mef_dev_parent, array( 'meno-dietata' => 'Jakub Hudák', 'kurz' => array( 'Šach' ) ) );

// Prihláška: všetkých päť dní, potom žiadny deň (prázdne pole na konci).
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-08-25 07:58' ), null, array( 'dni' => array( 'Pondelok', 'Utorok', 'Streda', 'Štvrtok', 'Piatok' ) ) );
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-08-26 20:15' ), null, array( 'dni' => array(), 'poznamka' => 'Dni zatiaľ nevieme, rozvrh dostaneme až v septembri.' ) );

// Prihláška: poznámka na viac riadkov, riadky začínajú pomlčkou a plusom.
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-09-02 17:40' ), null, array( 'poznamka' => "- treba doniesť vlastné pomôcky?\n- platí sa vopred alebo mesačne?\n+ máme záujem aj o súrodeneckú zľavu" ) );

// Prihláška: dátum narodenia 29. februára.
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-09-03 19:19' ), null, array( 'datum-narodenia' => '2020-02-29' ) );

// Prihláška: hranice septembra presne o polnoci.
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-09-01 00:00:30' ) );
$mef_dev_items[] = mef_dev_prihlaska( mef_dev_ts( '2026-09-30 23:59:10' ) );

// Prihláška: e-mail sa neodoslal.
$mef_dev_failed           = mef_dev_prihlaska( mef_dev_ts( '2026-09-08 09:30' ) );
$mef_dev_failed['status'] = 'mail_failed';
$mef_dev_items[]          = $mef_dev_failed;

// Prihláška: v koši.
$mef_dev_trashed          = mef_dev_prihlaska( mef_dev_ts( '2026-09-09 10:10' ), null, array( 'poznamka' => 'Omylom odoslané dvakrát.' ) );
$mef_dev_trashed['trash'] = true;
$mef_dev_items[]          = $mef_dev_trashed;

// Prihláška: spam od robota.
$mef_dev_item             = mef_dev_prihlaska(
	mef_dev_ts( '2026-09-12 03:33' ),
	array( 'name' => 'http://spam.example.net', 'email' => 'bot@example.net' ),
	array(
		'meno-dietata'    => 'asdf asdf',
		'datum-narodenia' => '1900-01-01',
		'telefon'         => '123',
		'poznamka'        => 'Best prices https://spam.example.net https://spam.example.net/2',
	)
);
$mef_dev_item['status']   = 'spam';
$mef_dev_item['spam_log'] = array(
	array(
		'agent'  => 'disallowed_list',
		'reason' => 'Disallowed words (http) are used.',
	),
);
$mef_dev_items[]          = $mef_dev_item;

// Bežné správy: kontakty rovnomerne cez rok, prihlášky najmä pred začiatkom školského roka.
for ( $i = 0; $i < 40; $i++ ) {
	$mef_dev_items[] = mef_dev_kontakt( mef_dev_random_ts( '2025-10-15', '2026-10-12 20:00' ) );
}
for ( $i = 0; $i < 45; $i++ ) {
	$mef_dev_items[] = mef_dev_prihlaska(
		$i < 27
			? mef_dev_random_ts( '2026-08-15', '2026-09-30 22:00' )
			: mef_dev_random_ts( '2025-10-15', '2026-10-12 20:00' )
	);
}

// Objednávky katalógu, kým formulár existoval (november 2025 až február 2026).
for ( $i = 0; $i < 4; $i++ ) {
	$mef_dev_items[] = mef_dev_objednavka( mef_dev_random_ts( '2025-11-01', '2026-02-28 20:00' ) );
}

// Veľký objem na test výkonu.
for ( $i = 0; $i < $mef_dev_bulk; $i++ ) {
	$mef_dev_ts      = mef_dev_random_ts( '2024-01-01', '2026-10-12 20:00' );
	$mef_dev_item    = mt_rand( 0, 1 ) ? mef_dev_kontakt( $mef_dev_ts ) : mef_dev_prihlaska( $mef_dev_ts );
	$mef_dev_item['bulk'] = true;
	$mef_dev_items[] = $mef_dev_item;
}

usort(
	$mef_dev_items,
	static function ( $a, $b ) {
		return $a['ts'] <=> $b['ts'];
	}
);

/*
 * Vymazanie starých správ a kontaktov z testovacích domén.
 */

global $wpdb;

$mef_dev_old = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Flamingo_Inbound_Message::post_type ) );
foreach ( $mef_dev_old as $mef_dev_id ) {
	wp_delete_post( (int) $mef_dev_id, true );
}
WP_CLI::log( sprintf( 'Vymazané správy: %d', count( $mef_dev_old ) ) );

$mef_dev_old = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title REGEXP %s AND post_title <> 'admin@example.com'",
		Flamingo_Contact::post_type,
		'@example\\.(com|org|net)$'
	)
);
foreach ( $mef_dev_old as $mef_dev_id ) {
	wp_delete_post( (int) $mef_dev_id, true );
}

/*
 * Zápis cez Flamingo, rovnaké údaje ako wpcf7_flamingo_submit().
 */

wp_defer_term_counting( true );

$mef_dev_serial = array();
$mef_dev_count  = array();
$mef_dev_site   = array(
	'site_title'       => get_bloginfo( 'name' ),
	'site_description' => get_bloginfo( 'description' ),
	'site_url'         => home_url(),
	'site_admin_email' => get_bloginfo( 'admin_email' ),
);
$mef_dev_progress = $mef_dev_bulk ? WP_CLI\Utils\make_progress_bar( 'Ukladám správy', count( $mef_dev_items ) ) : null;

foreach ( $mef_dev_items as $mef_dev_item ) {
	$mef_dev_form   = $mef_dev_forms[ $mef_dev_item['form'] ];
	$mef_dev_status = $mef_dev_item['status'] ?? 'mail_sent';
	$mef_dev_fields = $mef_dev_item['fields'];
	$mef_dev_ts     = $mef_dev_item['ts'];

	if ( 'kontakt' === $mef_dev_item['form'] ) {
		$mef_dev_email   = $mef_dev_fields['your-email'];
		$mef_dev_name    = $mef_dev_fields['your-name'];
		$mef_dev_subject = $mef_dev_fields['your-subject'];
	} elseif ( 'prihlaska-na-kurz' === $mef_dev_item['form'] ) {
		$mef_dev_email   = $mef_dev_fields['email'];
		$mef_dev_name    = $mef_dev_fields['meno-rodica'];
		$mef_dev_subject = 'Prihláška: ' . $mef_dev_fields['kurz'][0];
	} else {
		$mef_dev_email   = $mef_dev_fields['email'];
		$mef_dev_name    = $mef_dev_fields['meno'];
		$mef_dev_subject = 'Objednávka katalógu';
	}

	$mef_dev_serial[ $mef_dev_item['form'] ] = ( $mef_dev_serial[ $mef_dev_item['form'] ] ?? 0 ) + 1;

	$mef_dev_meta = array_merge(
		array(
			'serial_number'     => (string) $mef_dev_serial[ $mef_dev_item['form'] ],
			'remote_ip'         => mef_dev_ip(),
			'user_agent'        => mef_dev_user_agent(),
			'url'               => rest_url( 'contact-form-7/v1/contact-forms/' . $mef_dev_form['id'] . '/feedback' ),
			'date'              => wp_date( get_option( 'date_format' ), $mef_dev_ts ),
			'time'              => wp_date( get_option( 'time_format' ), $mef_dev_ts ),
			'post_id'           => $mef_dev_page ? (string) $mef_dev_page->ID : '',
			'post_name'         => $mef_dev_page ? $mef_dev_page->post_name : '',
			'post_title'        => $mef_dev_page ? $mef_dev_page->post_title : '',
			'post_url'          => $mef_dev_page ? get_permalink( $mef_dev_page ) : '',
			'post_author'       => false,
			'post_author_email' => false,
		),
		$mef_dev_site,
		array(
			'user_login'        => '',
			'user_email'        => '',
			'user_display_name' => '',
		)
	);

	if ( 'mail_sent' === $mef_dev_status && empty( $mef_dev_item['bulk'] ) ) {
		Flamingo_Contact::add(
			array(
				'email'          => $mef_dev_email,
				'name'           => $mef_dev_name,
				'last_contacted' => wp_date( 'Y-m-d H:i:s', $mef_dev_ts ),
			)
		);
	}

	$mef_dev_args = array(
		'channel'          => $mef_dev_form['channel'],
		'status'           => $mef_dev_status,
		'subject'          => $mef_dev_subject,
		'from'             => trim( sprintf( '%s <%s>', $mef_dev_name, $mef_dev_email ) ),
		'from_name'        => $mef_dev_name,
		'from_email'       => $mef_dev_email,
		'fields'           => $mef_dev_fields,
		'meta'             => $mef_dev_meta,
		'akismet'          => null,
		'spam'             => 'spam' === $mef_dev_status,
		'consent'          => $mef_dev_form['consent'],
		'timestamp'        => $mef_dev_ts,
		'posted_data_hash' => md5( serialize( $mef_dev_fields ) . $mef_dev_ts ),
		'recaptcha'        => null,
	);
	if ( $mef_dev_args['spam'] ) {
		$mef_dev_args['spam_log'] = $mef_dev_item['spam_log'] ?? array();
	}

	$mef_dev_message = Flamingo_Inbound_Message::add( $mef_dev_args );

	if ( ! empty( $mef_dev_item['trash'] ) ) {
		$mef_dev_message->trash();
	}

	$mef_dev_key                   = $mef_dev_item['form'] . ' / ' . ( ! empty( $mef_dev_item['trash'] ) ? 'trash' : $mef_dev_status );
	$mef_dev_count[ $mef_dev_key ] = ( $mef_dev_count[ $mef_dev_key ] ?? 0 ) + 1;

	if ( $mef_dev_progress ) {
		$mef_dev_progress->tick();
	}
}

if ( $mef_dev_progress ) {
	$mef_dev_progress->finish();
}

wp_defer_term_counting( false );

ksort( $mef_dev_count );
foreach ( $mef_dev_count as $mef_dev_key => $mef_dev_n ) {
	WP_CLI::log( sprintf( '%-34s %d', $mef_dev_key, $mef_dev_n ) );
}
WP_CLI::success( sprintf( 'Založené správy: %d', count( $mef_dev_items ) ) );
