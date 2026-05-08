<?php
/**
 * Legacy CleverSay synonym data, imported once during upgrade.
 *
 * Source: legacy ailiza_spellcheck table from this site.
 * The legacy schema was (id, valsp, newvalsp, mispell) where
 * newvalsp is the canonical keyword, valsp is comma-separated
 * synonyms, and mispell is comma-separated misspellings. Rows
 * with both valsp and mispell empty have been excluded (those
 * were KB keyword stubs, not true synonyms).
 *
 * is_phrase=1 is set when any variant contains a space or hyphen,
 * so multi-word phrases get replaced before tokenization rather
 * than after.
 *
 * Importer: Database::import_legacy_synonyms() -- non-destructive,
 * skips canonicals that already exist in the synonyms table.
 *
 * @package CleverSay
 */

namespace CleverSay;

if (!defined("ABSPATH")) { exit; }

function cleversay_legacy_synonyms(): array {
    return [
        ['canonical_word' => '911', 'variant_words' => '33', 'misspellings' => '9ii', 'is_phrase' => 0],
        ['canonical_word' => 'abroad', 'variant_words' => '', 'misspellings' => 'aborad', 'is_phrase' => 0],
        ['canonical_word' => 'address', 'variant_words' => 'adress', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'adult', 'variant_words' => 'nontraditional, non traditional', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'advisor', 'variant_words' => 'counselor, advise, advising', 'misspellings' => 'guidance, adviser', 'is_phrase' => 0],
        ['canonical_word' => 'application', 'variant_words' => '', 'misspellings' => 'aplication', 'is_phrase' => 0],
        ['canonical_word' => 'apply', 'variant_words' => 'aplying, aplly', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'attendance', 'variant_words' => 'attend', 'misspellings' => 'attendence', 'is_phrase' => 0],
        ['canonical_word' => 'book', 'variant_words' => 'textbook, cloth, clothe, store, apparel, merchandise, sweatshirt, attire, bookstore', 'misspellings' => 'boks', 'is_phrase' => 0],
        ['canonical_word' => 'bursar', 'variant_words' => 'cashier', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'calendar', 'variant_words' => 'break', 'misspellings' => 'calander', 'is_phrase' => 0],
        ['canonical_word' => 'change', 'variant_words' => 'switch', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'class', 'variant_words' => 'course, session', 'misspellings' => 'couse, classess', 'is_phrase' => 0],
        ['canonical_word' => 'commencement', 'variant_words' => 'ceremony', 'misspellings' => 'commancement', 'is_phrase' => 0],
        ['canonical_word' => 'consent', 'variant_words' => 'instructor consent, department consent', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'consortium', 'variant_words' => '', 'misspellings' => 'consortum', 'is_phrase' => 0],
        ['canonical_word' => 'contact', 'variant_words' => 'fax', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'cost', 'variant_words' => 'rate, how much', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'counseling', 'variant_words' => '', 'misspellings' => 'counceling', 'is_phrase' => 0],
        ['canonical_word' => 'covenant', 'variant_words' => 'wisconsin covenant', 'misspellings' => 'covenent', 'is_phrase' => 1],
        ['canonical_word' => 'credit', 'variant_words' => '', 'misspellings' => 'creidts, creits, credts, credt', 'is_phrase' => 0],
        ['canonical_word' => 'diploma', 'variant_words' => '', 'misspellings' => 'dipolma', 'is_phrase' => 0],
        ['canonical_word' => 'dpr', 'variant_words' => 'degree progress report, progress report', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'exam', 'variant_words' => 'final*', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'fafsa', 'variant_words' => 'free application for federal student aid, financial aid application, aid application, aid app', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'financial', 'variant_words' => 'money, aid', 'misspellings' => 'fincial, financail', 'is_phrase' => 0],
        ['canonical_word' => 'gdr', 'variant_words' => 'general degree requirements', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'gep', 'variant_words' => 'general education program', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'graduate', 'variant_words' => 'gmat, master, grad', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'housing', 'variant_words' => 'dorm, house', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'major', 'variant_words' => 'minor', 'misspellings' => 'miner', 'is_phrase' => 0],
        ['canonical_word' => 'mobilepoint', 'variant_words' => 'mobile, web app, app, cell phone, mobile phone', 'misspellings' => 'mobile point', 'is_phrase' => 1],
        ['canonical_word' => 'office', 'variant_words' => 'center', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'pace', 'variant_words' => '67 percent rule, 67 rule', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'pet', 'variant_words' => 'animal', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'phone', 'variant_words' => 'telephone, call', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'photo', 'variant_words' => 'picture', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'placement', 'variant_words' => 'ap', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'probation', 'variant_words' => 'academic standing', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'reapply', 'variant_words' => 'readmission, reinstate', 'misspellings' => 'reaply, reistated, reistate', 'is_phrase' => 0],
        ['canonical_word' => 'reentry', 'variant_words' => 're-entry, re-enroll, reenroll', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'register', 'variant_words' => 'sign up', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'registration', 'variant_words' => 'registrar', 'misspellings' => 'regsitration', 'is_phrase' => 0],
        ['canonical_word' => 'repeat', 'variant_words' => 'retake', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'requirement', 'variant_words' => 'criteria, standard, prerequisite, qualification', 'misspellings' => 'requrements, reqirements, requirments, requierments', 'is_phrase' => 0],
        ['canonical_word' => 'resident', 'variant_words' => 'residency', 'misspellings' => 'residnency', 'is_phrase' => 0],
        ['canonical_word' => 'sap', 'variant_words' => 'satisfactory academic progress, satisfactory academic, academic progress, satisfactory progress', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'schedule', 'variant_words' => 'schedule builder', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'scholarship', 'variant_words' => '', 'misspellings' => 'scholership, scolarship', 'is_phrase' => 0],
        ['canonical_word' => 'start', 'variant_words' => 'begin', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'status', 'variant_words' => 'stand', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'student', 'variant_words' => 'undergraduate, undergrad', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'suspension', 'variant_words' => 'suspend, expulsion', 'misspellings' => 'supension', 'is_phrase' => 0],
        ['canonical_word' => 'timetable', 'variant_words' => 'time table, catalog', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'transcript', 'variant_words' => '', 'misspellings' => 'transcrips, transcipts', 'is_phrase' => 0],
        ['canonical_word' => 'transfer', 'variant_words' => 'equivalent', 'misspellings' => 'tranfers, tansfer, tasfer', 'is_phrase' => 0],
        ['canonical_word' => 'tuition', 'variant_words' => '', 'misspellings' => 'tutions, tutons, tutins, tution, tutition, tuution', 'is_phrase' => 0],
        ['canonical_word' => 'verification', 'variant_words' => 'verify, verified', 'misspellings' => '', 'is_phrase' => 0],
        ['canonical_word' => 'veteran', 'variant_words' => 'military, va', 'misspellings' => 'vetran, vetrans', 'is_phrase' => 0],
        ['canonical_word' => 'w-drop', 'variant_words' => 'late drop, w drop, w drops', 'misspellings' => '', 'is_phrase' => 1],
        ['canonical_word' => 'winterim', 'variant_words' => 'winter break', 'misspellings' => 'winter, winterum', 'is_phrase' => 1],
        ['canonical_word' => 'withdraw', 'variant_words' => 'withdrawal, quit, unenroll, dropout', 'misspellings' => 'withdrawl', 'is_phrase' => 0],
        ['canonical_word' => 'work', 'variant_words' => '', 'misspellings' => 'workstudy', 'is_phrase' => 0],
    ];
}
