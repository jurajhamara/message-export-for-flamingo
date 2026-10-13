<?php
/**
 * Lokálny testovací web: založí alebo prepíše dva formuláre Contact Form 7.
 *
 * Spúšťa sa cez WP-CLI v priečinku lokálneho WordPressu:
 *   wp eval-file "~/Desktop/MESSAGE EXPORT FOR FLAMINGO/dev/setup-forms.php"
 *
 * Formulár "Kontakt" má štandardné názvy polí CF7 (your-name, your-email),
 * ktoré Flamingo rozpozná sám. "Prihláška na kurz" má vlastné názvy polí,
 * preto odosielateľa a predmet určujú nastavenia flamingo_email, flamingo_name
 * a flamingo_subject. Tak sa dá overiť, že export neráta s pevnými názvami.
 *
 * Súbor nepatrí do pluginu, priečinok dev/ musí byť v .distignore.
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Len pre lokálny web (WP_ENVIRONMENT_TYPE = local) cez WP-CLI.\n";
	return;
}

$mef_dev_forms = array(
	'kontakt'          => array(
		'title'      => 'Kontakt',
		'form'       => <<<'FORM'
<label> Meno a priezvisko
    [text* your-name autocomplete:name] </label>

<label> E-mail
    [email* your-email autocomplete:email] </label>

<label> Telefón (nepovinné)
    [tel your-phone autocomplete:tel] </label>

<label> Predmet
    [text* your-subject] </label>

<label> Správa
    [textarea* your-message] </label>

[submit "Odoslať"]
FORM
		,
		'mail'       => array(
			'subject'   => '[_site_title] "[your-subject]"',
			'body'      => "Od: [your-name] <[your-email]>\nTelefón: [your-phone]\nPredmet: [your-subject]\n\n[your-message]",
			'recipient' => '[_site_admin_email]',
		),
		'additional' => '',
	),
	'prihlaska-na-kurz' => array(
		'title'      => 'Prihláška na kurz',
		'form'       => <<<'FORM'
<label> Meno a priezvisko dieťaťa
    [text* meno-dietata] </label>

<label> Dátum narodenia dieťaťa
    [date* datum-narodenia] </label>

<label> Škola, ktorú dieťa navštevuje (nepovinné)
    [text skola] </label>

<label> Meno a priezvisko zákonného zástupcu
    [text* meno-rodica autocomplete:name] </label>

<label> E-mail
    [email* email autocomplete:email] </label>

<label> Telefón
    [tel* telefon autocomplete:tel] </label>

<label> Kurz
    [select* kurz first_as_label "Vyberte kurz" "Angličtina pre začiatočníkov" "Keramika" "Fotografia" "Programovanie pre deti" "Šach"] </label>

Vyhovujúce dni
[checkbox dni use_label_element "Pondelok" "Utorok" "Streda" "Štvrtok" "Piatok"]

Navštevovalo dieťa už podobný kurz?
[radio skusenosti use_label_element default:2 "Áno" "Nie"]

<label> Poznámka (nepovinné)
    [textarea poznamka] </label>

[acceptance suhlas] Súhlasím so spracovaním osobných údajov na účely prihlášky. [/acceptance]

[submit "Odoslať prihlášku"]
FORM
		,
		'mail'       => array(
			'subject'   => '[_site_title] Prihláška: [kurz]',
			'body'      => "Dieťa: [meno-dietata], nar. [datum-narodenia]\nŠkola: [skola]\nZákonný zástupca: [meno-rodica] <[email]>, [telefon]\nKurz: [kurz]\nDni: [dni]\nSkúsenosti: [skusenosti]\n\n[poznamka]",
			'recipient' => '[_site_admin_email]',
		),
		'additional' => "flamingo_email: \"[email]\"\nflamingo_name: \"[meno-rodica]\"\nflamingo_subject: \"Prihláška: [kurz]\"",
	),
);

foreach ( $mef_dev_forms as $mef_dev_slug => $mef_dev_def ) {
	$mef_dev_existing = get_page_by_path( $mef_dev_slug, OBJECT, 'wpcf7_contact_form' );

	if ( ! $mef_dev_existing && 'kontakt' === $mef_dev_slug ) {
		// Prevezme formulár, ktorý CF7 založí pri aktivácii.
		$mef_dev_existing = get_page_by_path( 'contact-form-1', OBJECT, 'wpcf7_contact_form' );
	}

	$mef_dev_form = $mef_dev_existing
		? wpcf7_contact_form( $mef_dev_existing->ID )
		: WPCF7_ContactForm::get_template();

	$mef_dev_mail = array_merge(
		$mef_dev_form->prop( 'mail' ),
		array(
			'sender' => '[_site_title] <wordpress@example.com>',
		),
		$mef_dev_def['mail']
	);

	$mef_dev_form->set_title( $mef_dev_def['title'] );
	$mef_dev_form->set_properties(
		array(
			'form'                => $mef_dev_def['form'],
			'mail'                => $mef_dev_mail,
			'mail_2'              => array_merge( $mef_dev_form->prop( 'mail_2' ), array( 'active' => false ) ),
			'additional_settings' => $mef_dev_def['additional'],
		)
	);
	$mef_dev_id = $mef_dev_form->save();

	wp_update_post(
		array(
			'ID'        => $mef_dev_id,
			'post_name' => $mef_dev_slug,
		)
	);

	WP_CLI::log( sprintf( 'Formulár %d: %s (%s)', $mef_dev_id, $mef_dev_def['title'], $mef_dev_slug ) );
}
