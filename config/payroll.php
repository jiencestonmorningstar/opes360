<?php

/*
 * Cameroon payroll: every rate, ceiling and scale the calculator uses.
 *
 * ── Why all of it is here ────────────────────────────────────────────────
 *
 * These are figures set by law and revised by finance act, not preferences.
 * A rate hard-coded in a service is a rate nobody can find in January when it
 * changes, and payroll arithmetic that is quietly a year out of date produces
 * declarations a business signs and is liable for. So the calculator reads
 * every number from here and holds none of its own, and an accountant who
 * knows this year's figures can correct the file without touching PHP.
 *
 * ── Provenance ──────────────────────────────────────────────────────────
 *
 * CONFIRMED against published references (see the notes on each block):
 *
 *   • CNPS PVID 4.2% employee + 4.2% employer, ceiling 750 000 F/month
 *   • CNPS prestations familiales 7% (general regime), employer only
 *   • CNPS accidents du travail 1.75% / 2.5% / 5% by risk group, employer only
 *   • IRPP: revenu net catégoriel = brut − 30% frais professionnels − PVID,
 *     less the 500 000 F abattement of article 29 CGI; annual scale
 *     10 / 15 / 25 / 35% at 2M / 3M / 5M
 *   • CAC 10% of the IRPP
 *   • CFC 1% employee, 1.5% employer
 *   • FNE 1%, employer only
 *   • TDL: ten bands, 250 F to 2 500 F a month, nothing below 62 000 F
 *   • SMIG 60 000 F/month for the general private sector (décret 2023/00338/PM)
 *
 * NOT independently confirmed, and the one thing to check before a first
 * real payroll run:
 *
 *   • the redevance audiovisuelle band amounts below. The exemption floor of
 *     50 000 F is confirmed and the banded structure is confirmed; the amount
 *     in each band is from a secondary source. `rav.enabled` is here so a
 *     business whose accountant has not confirmed them can switch the line
 *     off rather than withhold a figure it cannot stand behind.
 *
 * Rates a business genuinely differs on — its CNPS risk group, whether it is
 * on the agricultural family-allowance rate — are per-company settings on the
 * companies table, not edits to this file. What is here is the law's default.
 */
return [

    /*
     * Salaire minimum interprofessionnel garanti. Used to warn when a contract
     * is written below it, not to refuse one: an apprenticeship or a part-time
     * contract is legitimately below the full-time floor, and software that
     * blocks the entry teaches people to record the wrong number instead.
     */
    'smig' => 60000,

    /*
     * Decimal places every payroll figure is rounded to. Zero, because the CFA
     * franc has no subunit and a payslip carrying centimes is one nobody can
     * hand across a counter. An OHADA country on a decimal currency sets 2.
     */
    'rounding' => 0,

    /*
     * Caisse Nationale de Prévoyance Sociale.
     *
     * Pension (PVID) and family allowances are both computed on the salaire
     * cotisable capped at the ceiling; occupational risk is computed on the
     * whole of it. That asymmetry is real and is the most common mistake in a
     * hand-built payroll spreadsheet.
     */
    'cnps' => [
        // Monthly ceiling on the contributable salary — 9 000 000 F a year.
        'ceiling' => 750000,

        // Pension, invalidity and death. The only CNPS branch the employee pays.
        'pension' => [
            'employee' => 0.042,
            'employer' => 0.042,
            'capped' => true,
        ],

        // Prestations familiales. Employer only, capped.
        'family_allowances' => [
            'general' => 0.07,
            'agricultural' => 0.0565,
            'teaching' => 0.037,
            'capped' => true,
        ],

        /*
         * Accidents du travail et maladies professionnelles. Employer only, and
         * deliberately NOT capped — the risk does not stop at 750 000 F. The
         * group is assigned to the business by CNPS according to what it does.
         */
        'occupational_risk' => [
            'groups' => [
                'a' => ['rate' => 0.0175, 'label' => 'Group A — low risk (offices, trade, services)'],
                'b' => ['rate' => 0.025, 'label' => 'Group B — medium risk (workshops, transport, agriculture)'],
                'c' => ['rate' => 0.05, 'label' => 'Group C — high risk (construction, extraction, heavy industry)'],
            ],
            'default' => 'a',
            'capped' => false,
        ],
    ],

    /*
     * Impôt sur le Revenu des Personnes Physiques, traitements et salaires.
     *
     * The base is annual by law, so the calculator annualises the month,
     * applies the scale, and divides the result by twelve. Applying a
     * twelfth-scale to a month instead gives the same answer for a steady
     * salary and a different one the month a bonus lands, and the annual
     * reading is the one the DGI reconciles against.
     */
    'irpp' => [
        // Frais professionnels, article 34 CGI. `cap` is null because the code
        // states the 30% without a ceiling; it is a key rather than a constant
        // so a finance act that introduces one is a one-line change.
        'professional_expenses' => ['rate' => 0.30, 'cap' => null],

        // Article 29 CGI: a flat 500 000 F off the annual base.
        'annual_allowance' => 500000,

        // Whether the employee's CNPS pension contribution comes off the base
        // before the abattement. It does — article 34 CGI.
        'deduct_pension' => true,

        // Annual bands. `upto` null is the top band.
        'bands' => [
            ['upto' => 2000000, 'rate' => 0.10],
            ['upto' => 3000000, 'rate' => 0.15],
            ['upto' => 5000000, 'rate' => 0.25],
            ['upto' => null, 'rate' => 0.35],
        ],

        // Centimes additionnels communaux, levied on the tax itself.
        'cac' => 0.10,
    ],

    /*
     * Crédit Foncier du Cameroun. Both sides pay, on the gross taxable salary,
     * uncapped.
     */
    'cfc' => [
        'employee' => 0.01,
        'employer' => 0.015,
    ],

    /* Fonds National de l'Emploi. Employer only, on the gross taxable salary. */
    'fne' => [
        'employer' => 0.01,
    ],

    /*
     * Taxe de Développement Local. A fixed amount per band rather than a rate,
     * withheld from the employee and paid to the council where the business
     * sits. The bands are read against the BASE salary, not the gross — an
     * allowance does not move an employee up a TDL band.
     *
     * Nothing is owed below 62 000 F, and the top band is flat, so the yearly
     * maximum per employee is 30 000 F.
     */
    'tdl' => [
        'enabled' => true,
        'floor' => 62000,
        'basis' => 'base', // base|gross
        'bands' => [
            ['upto' => 75000, 'amount' => 250],
            ['upto' => 100000, 'amount' => 500],
            ['upto' => 125000, 'amount' => 750],
            ['upto' => 150000, 'amount' => 1000],
            ['upto' => 200000, 'amount' => 1250],
            ['upto' => 250000, 'amount' => 1500],
            ['upto' => 300000, 'amount' => 2000],
            ['upto' => 500000, 'amount' => 2250],
            ['upto' => null, 'amount' => 2500],
        ],
    ],

    /*
     * Redevance audiovisuelle, withheld for the CRTV.
     *
     * The floor and the banded shape are confirmed; the amounts are from a
     * secondary source and are the one block in this file to have an
     * accountant confirm before a first live run. See the provenance note at
     * the top — switch `enabled` off rather than withhold a figure the
     * business cannot justify to an employee who asks.
     */
    'rav' => [
        'enabled' => true,
        'floor' => 50000,
        'basis' => 'base',
        'bands' => [
            ['upto' => 100000, 'amount' => 750],
            ['upto' => 200000, 'amount' => 1950],
            ['upto' => 300000, 'amount' => 3250],
            ['upto' => 400000, 'amount' => 4550],
            ['upto' => 500000, 'amount' => 5850],
            ['upto' => 600000, 'amount' => 7150],
            ['upto' => 700000, 'amount' => 8450],
            ['upto' => 800000, 'amount' => 9750],
            ['upto' => 900000, 'amount' => 11050],
            ['upto' => 1000000, 'amount' => 12350],
            ['upto' => null, 'amount' => 13000],
        ],
    ],

    /*
     * Leave. The Code du travail gives a day and a half of paid leave per month
     * of actual service — eighteen working days a year — with additions for
     * seniority and for mothers of young children that a business applies by
     * hand rather than by rule, because the conditions are specific.
     */
    'leave' => [
        'accrual_days_per_month' => 1.5,
        'types' => [
            'annual' => 'Annual leave',
            'sick' => 'Sick leave',
            'maternity' => 'Maternity leave',
            'paternity' => 'Paternity leave',
            'compassionate' => 'Compassionate leave',
            'unpaid' => 'Unpaid leave',
            'other' => 'Other',
        ],
        // Which types come out of the accrued annual balance.
        'deducts_balance' => ['annual'],
        // Which types are paid, i.e. leave the salary alone.
        'paid' => ['annual', 'sick', 'maternity', 'paternity', 'compassionate'],
    ],

    /*
     * Contract types under the Code du travail. A CDD has a maximum term of two
     * years renewable once; the app records the dates and warns, it does not
     * enforce a rule whose exceptions are a lawyer's business.
     */
    'contract_types' => [
        'cdi' => 'CDI — permanent',
        'cdd' => 'CDD — fixed term',
        'stage' => 'Internship',
        'essai' => 'Trial period',
        'prestation' => 'Service contract',
    ],

    /*
     * The SYSCOHADA accounts payroll posts to. Roles rather than numbers, for
     * the reason ChartOfAccounts gives: a business that subdivides 661 can
     * point the role wherever it likes.
     */
    'accounts' => [
        'wages' => 'wages',                 // 661 Rémunérations directes
        'allowances' => 'staff_allowances', // 663 Indemnités forfaitaires
        'social_charges' => 'social_charges', // 664 Charges sociales patronales
        'payroll_taxes' => 'payroll_taxes',   // 641 Impôts et taxes directs
        'net_due' => 'staff_payable',         // 422 Personnel, rémunérations dues
        'social_payable' => 'social_payable', // 431 Sécurité sociale
        'tax_withheld' => 'tax_withheld',     // 447 État, impôts retenus à la source
    ],
];
