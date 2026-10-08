<?php

namespace Database\Seeders;

/**
 * Everything DemoDataSeeder writes, as plain data.
 *
 * Kept apart from the seeder so the people and the jobs can be read and
 * edited without wading through the code that writes them. Every date is a
 * whole-day offset from the office's today at the moment the seeder runs, so
 * the statuses come out right whenever it is run - and stay right for the
 * fourteen days after, which is what the windows below are sized for:
 *
 *   - Pending work starts no earlier than day +16, so it is still Pending on
 *     day +14.
 *   - Ongoing work runs to day +16 or later, so it never runs out of dates
 *     inside the window and turns into Needs Rescheduling.
 *   - Every live target date is day +16 or later, so nothing reads as past
 *     its target date either.
 *
 * Awaiting Client Confirmation is the one status with its own clock: the
 * nightly job completes it after the configured number of days (seven unless
 * the Super Admin changed it). See the seeder's class comment.
 */
final class DemoDataCatalog
{
    public const EMAIL_DOMAIN = 'example.com';

    public const AIRCON_INSTALLATION = 'Aircon Installation';

    public const AIRCON_REPAIR = 'Aircon Repair';

    public const AIRCON_CLEANING = 'Aircon Cleaning';

    public const DUCTING_FABRICATION = 'Ducting Fabrication';

    public const DUCTING_INSTALLATION = 'Ducting Installation';

    public const HEATING_VENTILATION = 'Heating Ventilation';

    /**
     * The six project types, which are also the six technician specialties.
     *
     * @var array<int, string>
     */
    public const PROJECT_TYPES = [
        self::AIRCON_INSTALLATION,
        self::AIRCON_REPAIR,
        self::AIRCON_CLEANING,
        self::DUCTING_FABRICATION,
        self::DUCTING_INSTALLATION,
        self::HEATING_VENTILATION,
    ];

    /**
     * The office staff, the lead technicians and the technicians.
     *
     * @return array<int, array{role: string, first_name: string, middle_name: string, last_name: string, email: string, position: string, skills: array<int, string>}>
     */
    public static function employees(): array
    {
        $admin = fn (string $first, string $middle, string $last, string $email, string $position): array => [
            'role' => 'admin',
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'email' => $email,
            'position' => $position,
            'skills' => [],
        ];

        $crew = fn (string $role, string $first, string $middle, string $last, string $email, string $position, array $skills): array => [
            'role' => $role,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'email' => $email,
            'position' => $position,
            'skills' => $skills,
        ];

        return [
            $admin('Cristina', 'Dizon', 'Reyes', 'cristina.reyes', 'Operations Manager'),
            $admin('Jerome', 'Bautista', 'Villanueva', 'jerome.villanueva', 'Project Coordinator'),
            $admin('Angelica', 'Mercado', 'Ramos', 'angelica.ramos', 'Project Coordinator'),
            $admin('Rodel', 'Castillo', 'Navarro', 'rodel.navarro', 'Operations Supervisor'),
            $admin('Kristine', 'Aquino', 'Mendoza', 'kristine.mendoza', 'Administrative Officer'),
            $admin('Paolo', 'Salazar', 'Gonzales', 'paolo.gonzales', 'Procurement Officer'),
            $admin('Liza', 'Fernandez', 'Domingo', 'liza.domingo', 'Accounts Officer'),
            $admin('Noel', 'Pascual', 'Garcia', 'noel.garcia', 'Scheduling Officer'),
            $admin('Hazel', 'Torres', 'Manalo', 'hazel.manalo', 'Customer Relations Officer'),
            $admin('Arnel', 'De Guzman', 'Santiago', 'arnel.santiago', 'Quality Assurance Officer'),

            $crew('lead_technician', 'Ramon', 'Cruz', 'Dela Cruz', 'ramon.delacruz', 'Lead HVAC Technician', [self::AIRCON_INSTALLATION, self::AIRCON_REPAIR, self::DUCTING_INSTALLATION]),
            $crew('lead_technician', 'Eduardo', 'Valdez', 'Soriano', 'eduardo.soriano', 'Lead Ducting Technician', [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION, self::HEATING_VENTILATION]),
            $crew('lead_technician', 'Marlon', 'Agustin', 'Bernardo', 'marlon.bernardo', 'Lead HVAC Technician', [self::AIRCON_INSTALLATION, self::AIRCON_CLEANING, self::AIRCON_REPAIR]),
            $crew('lead_technician', 'Rogelio', 'Panganiban', 'Tolentino', 'rogelio.tolentino', 'Lead Ventilation Technician', [self::HEATING_VENTILATION, self::DUCTING_INSTALLATION, self::AIRCON_INSTALLATION]),
            $crew('lead_technician', 'Jayson', 'Lim', 'Robles', 'jayson.robles', 'Lead Service Technician', [self::AIRCON_REPAIR, self::AIRCON_CLEANING, self::AIRCON_INSTALLATION]),
            $crew('lead_technician', 'Danilo', 'Ocampo', 'Fajardo', 'danilo.fajardo', 'Lead Ducting Technician', [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION, self::AIRCON_INSTALLATION]),
            $crew('lead_technician', 'Ricardo', 'Morales', 'Evangelista', 'ricardo.evangelista', 'Lead HVAC Technician', [self::AIRCON_INSTALLATION, self::HEATING_VENTILATION, self::AIRCON_REPAIR]),
            $crew('lead_technician', 'Alvin', 'Cabrera', 'Macaraeg', 'alvin.macaraeg', 'Lead Service Technician', [self::AIRCON_CLEANING, self::AIRCON_REPAIR, self::DUCTING_INSTALLATION]),
            $crew('lead_technician', 'Gilbert', 'Rivera', 'Estrada', 'gilbert.estrada', 'Lead Ventilation Technician', [self::HEATING_VENTILATION, self::DUCTING_FABRICATION, self::AIRCON_INSTALLATION]),
            $crew('lead_technician', 'Romeo', 'Del Rosario', 'Ignacio', 'romeo.ignacio', 'Lead HVAC Technician', [self::AIRCON_INSTALLATION, self::AIRCON_REPAIR, self::AIRCON_CLEANING]),

            $crew('technician', 'Mark Anthony', 'Gomez', 'Perez', 'markanthony.perez', 'HVAC Technician', [self::AIRCON_INSTALLATION, self::AIRCON_CLEANING]),
            $crew('technician', 'John Paul', 'Rosales', 'Medina', 'johnpaul.medina', 'Service Technician', [self::AIRCON_REPAIR, self::AIRCON_CLEANING, self::AIRCON_INSTALLATION]),
            $crew('technician', 'Christian', 'Valencia', 'Aguilar', 'christian.aguilar', 'Ducting Fabricator', [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION]),
            $crew('technician', 'Jerald', 'Manuel', 'Cortez', 'jerald.cortez', 'Ducting Installer', [self::DUCTING_INSTALLATION, self::HEATING_VENTILATION]),
            $crew('technician', 'Kevin', 'Samonte', 'Dizon', 'kevin.dizon', 'HVAC Technician', [self::AIRCON_INSTALLATION, self::AIRCON_REPAIR]),
            $crew('technician', 'Ronald', 'Abad', 'Francisco', 'ronald.francisco', 'Ventilation Technician', [self::HEATING_VENTILATION, self::DUCTING_INSTALLATION, self::AIRCON_INSTALLATION]),
            $crew('technician', 'Jomar', 'Velasco', 'Santos', 'jomar.santos', 'Service Technician', [self::AIRCON_CLEANING, self::AIRCON_REPAIR, self::AIRCON_INSTALLATION]),
            $crew('technician', 'Ariel', 'Tan', 'Buenaventura', 'ariel.buenaventura', 'Ducting Fabricator', [self::DUCTING_FABRICATION, self::HEATING_VENTILATION]),
            $crew('technician', 'Nestor', 'Galang', 'Pineda', 'nestor.pineda', 'HVAC Technician', [self::AIRCON_INSTALLATION, self::DUCTING_INSTALLATION]),
            $crew('technician', 'Raymond', 'Lopez', 'Andrada', 'raymond.andrada', 'Service Technician', [self::AIRCON_REPAIR, self::AIRCON_CLEANING, self::HEATING_VENTILATION]),
        ];
    }

    /**
     * The twenty Registered Users the projects belong to.
     *
     * Keyed by the local part of the email address, which is what each project
     * names as its client. The address on a residential client is where they
     * live; the projects there are done at that address.
     *
     * @return array<string, array{first_name: string, middle_name: string, last_name: string, client_type: string, company_name: ?string, address: string}>
     */
    public static function clients(): array
    {
        $commercial = fn (string $first, string $middle, string $last, string $company, string $address): array => [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'client_type' => 'Commercial',
            'company_name' => $company,
            'address' => $address,
        ];

        $residential = fn (string $first, string $middle, string $last, string $address): array => [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'client_type' => 'Residential',
            'company_name' => null,
            'address' => $address,
        ];

        return [
            'teresa.villareal' => $commercial('Teresa', 'Ong', 'Villareal', 'Southwoods Medical Clinic', '2/F Southwoods Mall, Brgy. San Francisco, Biñan, Laguna'),
            'antonio.saldana' => $commercial('Antonio', 'Reyes', 'Saldaña', 'Carmona Fresh Foods Corp.', 'Lot 3 Phase 1, Carmona Industrial Estate, Brgy. Maduya, Carmona, Cavite'),
            'melanie.tiongson' => $commercial('Melanie', 'Garcia', 'Tiongson', 'Kusina ni Lola Restaurant', 'Km. 47 Aguinaldo Highway, Brgy. Biga, Silang, Cavite'),
            'josephine.alcantara' => $commercial('Josephine', 'Cruz', 'Alcantara', 'Bright Minds Learning Center', 'Governor\'s Drive cor. Mangubat Ave., Brgy. Burol, Dasmariñas, Cavite'),
            'fernando.co' => $commercial('Fernando', 'Uy', 'Co', 'Pacific Crest Logistics Inc.', 'Bldg. 6, Gateway Business Park, Brgy. Javalera, General Trias, Cavite'),
            'mark.javier' => $commercial('Mark', 'Ilagan', 'Javier', 'Sta. Rosa Fitness Hub', 'Balibago Complex, Brgy. Balibago, Santa Rosa, Laguna'),
            'patricia.yap' => $commercial('Patricia', 'Lopez', 'Yap', 'Greenfield Business Suites', 'Commerce Ave., Filinvest City, Alabang, Muntinlupa City'),
            'rosario.herrera' => $commercial('Rosario', 'Medina', 'Herrera', 'Calamba Bay Hotel', 'National Highway, Brgy. Real, Calamba, Laguna'),

            'leonardo.mercado' => $residential('Leonardo', 'Bautista', 'Mercado', 'Blk 12 Lot 8 Phase 2, Lancaster New City, Brgy. Navarro, General Trias, Cavite'),
            'imelda.castro' => $residential('Imelda', 'Santos', 'Castro', 'Lot 5 Blk 3, Villa de Calamba Subd., Brgy. Parian, Calamba, Laguna'),
            'gregorio.vergara' => $residential('Gregorio', 'Ramos', 'Vergara', '27 Sampaguita St., Brgy. Maduya, Carmona, Cavite'),
            'analyn.roxas' => $residential('Analyn', 'Dela Paz', 'Roxas', 'Blk 7 Lot 21, Avida Settings, Brgy. Pulong Santa Cruz, Santa Rosa, Laguna'),
            'ferdinand.salcedo' => $residential('Ferdinand', 'Aquino', 'Salcedo', 'Unit 1204 Tower 2, Avida Towers Alabang, Muntinlupa City'),
            'rowena.padilla' => $residential('Rowena', 'Ventura', 'Padilla', '45 Mabini St., Brgy. Poblacion, Biñan, Laguna'),
            'emmanuel.gutierrez' => $residential('Emmanuel', 'Sison', 'Gutierrez', 'Blk 2 Lot 14, Southwoods Ecocentrum, Brgy. San Francisco, Biñan, Laguna'),
            'charmaine.tan' => $residential('Charmaine', 'Ong', 'Tan', '88 Acacia Ave., Ayala Westgrove Heights, Silang, Cavite'),
            'wilfredo.bustamante' => $residential('Wilfredo', 'Lagman', 'Bustamante', 'Lot 9 Blk 6, Mabuhay City, Brgy. Mamatid, Cabuyao, Laguna'),
            'josefina.abella' => $residential('Josefina', 'Marquez', 'Abella', '112 Gen. Emilio Aguinaldo Highway, Brgy. Salitran, Dasmariñas, Cavite'),
            'dennis.fuentes' => $residential('Dennis', 'Rosario', 'Fuentes', 'Blk 4 Lot 2, Camella Carmona, Brgy. Lantic, Carmona, Cavite'),
            'maricel.sarmiento' => $residential('Maricel', 'Domingo', 'Sarmiento', '16 Narra St., Brgy. Pacita 1, San Pedro, Laguna'),
        ];
    }

    /**
     * The fifty projects.
     *
     * `scenario` is how the project ends up:
     *
     *   unscheduled, pending, ongoing      what the dates imply
     *   overdue                            Ongoing with every date passed - Needs Rescheduling
     *   on_hold                            paused on `held_on`; `preserved` is the rest of its plan
     *   awaiting                           work done, waiting on the client
     *   completed                          closed by `method`
     *   cancelled                          called off on `cancelled`
     *   archived                           a completed or cancelled job, archived on `archived`
     *
     * `ranges` are the booked days as [first, last] offsets, inclusive.
     * `technicians` is how many supporting technicians join the lead.
     * `incident`, `delay` and `hold_reason` each become an incident report
     * from the lead.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function projects(): array
    {
        return [
            // ---------------------------------------------------------------
            // Completed - the company's track record over the past year
            // ---------------------------------------------------------------
            [
                'name' => 'Split-Type Aircon Installation - Mercado Residence',
                'client' => 'leonardo.mercado',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Supply and installation of two 1.5 HP inverter split-type units for the master bedroom and the living room, including copper piping, condensate drain line and a dedicated 20A breaker.',
                'quotation' => 86500,
                'created' => -310,
                'ranges' => [[-300, -298]],
                'target' => -295,
                'technicians' => 2,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Annual Preventive Maintenance - Southwoods Medical Clinic',
                'client' => 'teresa.villareal',
                'types' => [self::AIRCON_CLEANING],
                'scenario' => 'completed',
                'description' => 'Annual preventive maintenance of 24 wall-mounted and ceiling cassette units across the consultation rooms, laboratory and waiting area: chemical coil wash, filter replacement, drain flushing and electrical checks.',
                'quotation' => 54000,
                'created' => -295,
                'ranges' => [[-285, -283]],
                'target' => -280,
                'technicians' => 2,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Kitchen Exhaust and Make-Up Air System - Kusina ni Lola',
                'client' => 'melanie.tiongson',
                'types' => [self::HEATING_VENTILATION, self::DUCTING_FABRICATION],
                'scenario' => 'completed',
                'description' => 'Design, fabrication and installation of a stainless-steel kitchen hood exhaust with a 2 HP centrifugal fan and a fresh-air make-up unit to keep the dining area free of smoke and cooking odor.',
                'quotation' => 685000,
                'incident' => ['title' => 'Grease Build-Up in Existing Riser', 'description' => 'Heavy grease build-up was found inside the existing exhaust riser. The riser was degreased before the new hood was connected, to avoid a fire hazard.'],
                'created' => -290,
                'ranges' => [[-270, -252]],
                'target' => -250,
                'technicians' => 3,
                'method' => 'admin_confirmed',
                'channel' => 'call',
                'note' => 'Ms. Tiongson called the office to confirm the exhaust system is working well and accepted the turnover.',
            ],
            [
                'name' => 'Inverter Aircon Replacement - Castro Residence',
                'client' => 'imelda.castro',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Removal of a 15-year-old window-type unit and installation of a 1.0 HP inverter split-type unit in the guest bedroom, reusing the existing wall opening.',
                'quotation' => 72800,
                'created' => -248,
                'ranges' => [[-240, -239]],
                'target' => -235,
                'technicians' => 1,
                'method' => 'auto_completed',
            ],
            [
                'name' => 'Classroom Aircon Installation (8 Units) - Bright Minds Learning Center',
                'client' => 'josephine.alcantara',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Supply and installation of eight 2.5 HP floor-mounted units for the second-floor classrooms, with a new sub-panel and surface-mounted conduit runs.',
                'quotation' => 612000,
                'created' => -240,
                'ranges' => [[-225, -214]],
                'target' => -212,
                'technicians' => 3,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Compressor Repair - Vergara Residence',
                'client' => 'gregorio.vergara',
                'types' => [self::AIRCON_REPAIR],
                'scenario' => 'completed',
                'description' => 'Diagnosis and repair of a 2.0 HP split-type unit that trips the breaker on start-up. Replacement of the run capacitor and contactor, and a refrigerant top-up.',
                'quotation' => 18500,
                'created' => -208,
                'ranges' => [[-205, -205]],
                'target' => -203,
                'technicians' => 1,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Warehouse Ventilation Upgrade - Pacific Crest Logistics',
                'client' => 'fernando.co',
                'types' => [self::HEATING_VENTILATION, self::DUCTING_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Installation of six roof-mounted exhaust fans and galvanized intake ducting for the 2,400 sqm storage area to bring down afternoon heat build-up around the racking.',
                'quotation' => 940000,
                'created' => -205,
                'ranges' => [[-190, -172]],
                'target' => -170,
                'technicians' => 3,
                'method' => 'admin_confirmed',
                'channel' => 'message',
                'note' => 'Mr. Co confirmed acceptance of the ventilation works by email after their facilities team completed its own walk-through.',
            ],
            [
                'name' => 'Gym Ducted Aircon System - Sta. Rosa Fitness Hub',
                'client' => 'mark.javier',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION, self::AIRCON_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Fabrication and installation of insulated supply and return ducting for the main workout floor, served by two 10 TR ducted units, with linear slot diffusers along the mirror wall.',
                'quotation' => 1450000,
                'incident' => ['title' => 'Equipment Delivery Delay', 'description' => 'The supplier moved the delivery of the two 10 TR units by three days. The crew carried on with duct fabrication in the meantime.'],
                'created' => -180,
                'ranges' => [[-160, -141]],
                'target' => -143,
                'technicians' => 3,
                'method' => 'client_confirmed',
                'target_change' => ['offset' => -140, 'days_after_created' => 30, 'reason' => 'Delivery of the 10 TR units was moved by the supplier.'],
            ],
            [
                'name' => 'Aircon General Cleaning (4 Units) - Roxas Residence',
                'client' => 'analyn.roxas',
                'types' => [self::AIRCON_CLEANING],
                'scenario' => 'completed',
                'description' => 'General cleaning of four split-type units: indoor coil and blower wash, outdoor condenser wash, filter cleaning and drain line flushing.',
                'quotation' => 6400,
                'created' => -133,
                'ranges' => [[-130, -130]],
                'target' => -128,
                'technicians' => 1,
                'method' => 'auto_completed',
            ],
            [
                'name' => 'Office Fit-Out Ducting (5th Floor) - Greenfield Business Suites',
                'client' => 'patricia.yap',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Fabrication and installation of supply, return and fresh-air ducting for a 650 sqm office fit-out, tied into the building\'s existing AHU, with volume dampers and ceiling diffusers.',
                'quotation' => 1180000,
                'incident' => ['title' => 'Clash with Sprinkler Line', 'description' => 'The main supply duct clashed with a sprinkler pipe at grid C-4. The duct was rerouted with an offset after checking with the building engineer.'],
                'created' => -140,
                'ranges' => [[-125, -108]],
                'target' => -105,
                'technicians' => 3,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Refrigerant Leak Repair and Recharge - Salcedo Residence',
                'client' => 'ferdinand.salcedo',
                'types' => [self::AIRCON_REPAIR],
                'scenario' => 'completed',
                'description' => 'Leak search on a 1.5 HP split-type unit that stopped cooling, brazing of the leaking flare joint, nitrogen pressure test, vacuum and R32 recharge.',
                'quotation' => 9800,
                'created' => -101,
                'ranges' => [[-98, -98]],
                'target' => -96,
                'technicians' => 1,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Guest Room Aircon Servicing (30 Rooms) - Calamba Bay Hotel',
                'client' => 'rosario.herrera',
                'types' => [self::AIRCON_CLEANING, self::AIRCON_REPAIR],
                'scenario' => 'completed',
                'description' => 'Cleaning and servicing of the wall-mounted units in thirty guest rooms, done floor by floor to keep rooms available, with minor repairs on the units found faulty.',
                'quotation' => 96000,
                'created' => -100,
                'ranges' => [[-90, -86]],
                'target' => -85,
                'technicians' => 2,
                'method' => 'admin_confirmed',
                'channel' => 'in_person',
                'note' => 'Ms. Herrera signed the service acceptance form at the hotel front office during the final walk-through.',
            ],
            [
                'name' => 'Window-to-Split Conversion - Padilla Residence',
                'client' => 'rowena.padilla',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Removal of two window-type units, patching of the wall openings and installation of two 1.0 HP inverter split-type units.',
                'quotation' => 58900,
                'created' => -82,
                'ranges' => [[-75, -74]],
                'target' => -72,
                'technicians' => 2,
                'method' => 'client_confirmed',
            ],
            [
                'name' => 'Cold Room Evaporator Repair - Carmona Fresh Foods',
                'client' => 'antonio.saldana',
                'types' => [self::AIRCON_REPAIR],
                'scenario' => 'completed',
                'description' => 'Repair of the cold room evaporator that kept icing up: replacement of the defrost heater and the faulty expansion valve, then a 24-hour temperature log to confirm it holds at 2°C.',
                'quotation' => 42500,
                'created' => -66,
                'ranges' => [[-62, -60]],
                'target' => -58,
                'technicians' => 2,
                'method' => 'auto_completed',
            ],
            [
                'name' => 'Multi-Split Installation - Gutierrez Residence',
                'client' => 'emmanuel.gutierrez',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'completed',
                'description' => 'Installation of a multi-split system with one 3.0 HP outdoor unit serving three bedroom wall-mounted units, with concealed piping through the ceiling.',
                'quotation' => 138000,
                'created' => -60,
                'ranges' => [[-52, -49]],
                'target' => -48,
                'technicians' => 2,
                'method' => 'client_confirmed',
            ],

            // ---------------------------------------------------------------
            // Archived - old, closed work moved off the active lists
            // ---------------------------------------------------------------
            [
                'name' => 'Server Room Precision Cooling - Pacific Crest Logistics',
                'client' => 'fernando.co',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'archived',
                'archived_from' => 'completed',
                'description' => 'Installation of two 3.0 HP units on alternating duty with a changeover controller to keep the server room below 22°C around the clock.',
                'quotation' => 465000,
                'created' => -345,
                'ranges' => [[-330, -322]],
                'target' => -320,
                'technicians' => 2,
                'method' => 'client_confirmed',
                'archived' => -40,
            ],
            [
                'name' => 'Aircon Cleaning (2 Units) - Tan Residence',
                'client' => 'charmaine.tan',
                'types' => [self::AIRCON_CLEANING],
                'scenario' => 'archived',
                'archived_from' => 'completed',
                'description' => 'General cleaning of two split-type units in the bedrooms.',
                'quotation' => 3200,
                'created' => -325,
                'ranges' => [[-320, -320]],
                'target' => -318,
                'technicians' => 1,
                'method' => 'auto_completed',
                'archived' => -38,
            ],
            [
                'name' => 'Range Hood Exhaust Ducting - Bustamante Residence',
                'client' => 'wilfredo.bustamante',
                'types' => [self::DUCTING_INSTALLATION, self::HEATING_VENTILATION],
                'scenario' => 'archived',
                'archived_from' => 'completed',
                'description' => 'Installation of a 6-inch exhaust duct from the kitchen range hood to the outside wall with a weatherproof louver and backdraft damper.',
                'quotation' => 38000,
                'created' => -318,
                'ranges' => [[-310, -308]],
                'target' => -306,
                'technicians' => 1,
                'method' => 'client_confirmed',
                'archived' => -30,
            ],
            [
                'name' => 'Rooftop Exhaust Fan Installation - Kusina ni Lola',
                'client' => 'melanie.tiongson',
                'types' => [self::HEATING_VENTILATION],
                'scenario' => 'archived',
                'archived_from' => 'cancelled',
                'description' => 'Installation of two rooftop exhaust fans over the dishwashing area.',
                'quotation' => 120000,
                'created' => -245,
                'ranges' => [[-220, -216]],
                'target' => -214,
                'technicians' => 1,
                'cancelled' => -230,
                'reason' => 'Client decided to include the dishwashing area in the main kitchen exhaust project instead.',
                'remarks' => 'Scope merged into the kitchen exhaust and make-up air system already under contract.',
                'archived' => -35,
            ],

            // ---------------------------------------------------------------
            // Cancelled
            // ---------------------------------------------------------------
            [
                'name' => 'Floor-Mounted Aircon Installation - Abella Residence',
                'client' => 'josefina.abella',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'cancelled',
                'description' => 'Supply and installation of a 3.0 HP floor-mounted unit for the combined living and dining area.',
                'quotation' => 112000,
                'created' => -195,
                'ranges' => [[-180, -178]],
                'target' => -176,
                'technicians' => 1,
                'cancelled' => -185,
                'reason' => 'Client postponed the installation indefinitely due to budget constraints.',
                'remarks' => 'Client asked to be contacted again for a new quotation next year.',
            ],
            [
                'name' => 'Clinic Expansion Ducting - Southwoods Medical Clinic',
                'client' => 'teresa.villareal',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION],
                'scenario' => 'cancelled',
                'description' => 'Ducting extension to serve four new consultation rooms in the clinic\'s expansion area.',
                'quotation' => 720000,
                'created' => -165,
                'ranges' => [[-150, -135]],
                'target' => -133,
                'technicians' => 2,
                'cancelled' => -145,
                'reason' => 'Mall administration suspended the clinic\'s expansion permit.',
                'remarks' => 'Fabricated ducts were turned over to the client for storage. Work stopped after the main trunk line was hung.',
            ],
            [
                'name' => 'Aircon Repair - Fuentes Residence',
                'client' => 'dennis.fuentes',
                'types' => [self::AIRCON_REPAIR],
                'scenario' => 'cancelled',
                'description' => 'Repair of a 1.5 HP split-type unit with a noisy indoor blower.',
                'quotation' => 7500,
                'created' => -75,
                'ranges' => [[-70, -70]],
                'target' => -69,
                'technicians' => 1,
                'cancelled' => -72,
                'reason' => 'The unit was replaced by the manufacturer under warranty.',
                'remarks' => null,
            ],
            [
                'name' => 'Dormitory Aircon Installation - Bright Minds Learning Center',
                'client' => 'josephine.alcantara',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'cancelled',
                'description' => 'Installation of twelve 1.5 HP split-type units in the staff dormitory.',
                'quotation' => 540000,
                'created' => -55,
                'ranges' => [[-40, -30]],
                'target' => -28,
                'technicians' => 2,
                'cancelled' => -44,
                'reason' => 'The client awarded the contract to another supplier after re-bidding.',
                'remarks' => 'Assessment and quotation fees were waived.',
            ],

            // ---------------------------------------------------------------
            // On Hold
            // ---------------------------------------------------------------
            [
                'name' => 'Locker Room Ventilation - Sta. Rosa Fitness Hub',
                'client' => 'mark.javier',
                'types' => [self::HEATING_VENTILATION, self::DUCTING_INSTALLATION],
                'scenario' => 'on_hold',
                'description' => 'Exhaust ventilation for the men\'s and women\'s locker rooms and shower areas, with inline fans and moisture-resistant ducting.',
                'quotation' => 265000,
                'created' => -45,
                'ranges' => [[-34, -25]],
                'preserved' => [[-24, 20]],
                'held_on' => -25,
                'target' => 30,
                'technicians' => 1,
                'hold_reason' => 'Gym management asked to pause the work until the locker room re-tiling contractor finishes.',
            ],
            [
                'name' => 'Ducted Split System - Sarmiento Residence',
                'client' => 'maricel.sarmiento',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION, self::AIRCON_INSTALLATION],
                'scenario' => 'on_hold',
                'description' => 'A 5.0 TR ducted split system for the whole second floor with concealed ducting above a new gypsum ceiling.',
                'quotation' => 385000,
                'created' => -38,
                'ranges' => [[-28, -21]],
                'preserved' => [[-20, 18]],
                'held_on' => -21,
                'target' => 28,
                'technicians' => 2,
                'hold_reason' => 'Client is waiting for the ceiling contractor to finish the gypsum board before the ducts can be closed in.',
            ],
            [
                'name' => 'Production Area Ventilation - Carmona Fresh Foods',
                'client' => 'antonio.saldana',
                'types' => [self::HEATING_VENTILATION],
                'scenario' => 'on_hold',
                'description' => 'General ventilation for the food processing area: wall-mounted supply fans with filtered intakes and roof exhaust to keep the area within food-safety temperature limits.',
                'quotation' => 560000,
                'created' => -36,
                'ranges' => [[-26, -18]],
                'preserved' => [[-17, 22]],
                'held_on' => -18,
                'target' => 35,
                'technicians' => 1,
                'hold_reason' => 'Waiting on delivery of the imported food-grade supply fans, now expected in about three weeks.',
            ],

            // ---------------------------------------------------------------
            // Needs Rescheduling - ongoing work whose booked dates ran out
            // ---------------------------------------------------------------
            [
                'name' => 'Lobby Ceiling Cassette Installation - Greenfield Business Suites',
                'client' => 'patricia.yap',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'overdue',
                'description' => 'Replacement of the lobby\'s old units with six 3.0 HP ceiling cassette units, coordinated with the building\'s night-shift work permit.',
                'quotation' => 780000,
                'created' => -40,
                'ranges' => [[-30, -19]],
                'target' => 20,
                'technicians' => 2,
                'delay' => 'Building administration only allowed ceiling works after 10 PM, so the last two cassettes could not be hung within the booked dates.',
            ],
            [
                'name' => 'Function Hall Ducting Rerouting - Calamba Bay Hotel',
                'client' => 'rosario.herrera',
                'types' => [self::DUCTING_INSTALLATION, self::HEATING_VENTILATION],
                'scenario' => 'overdue',
                'description' => 'Rerouting of the function hall supply ducts around the new stage truss and adding two return-air grilles.',
                'quotation' => 430000,
                'created' => -35,
                'ranges' => [[-26, -16]],
                'target' => 18,
                'technicians' => 2,
                'delay' => 'The hall was booked for two weddings, so the hotel asked the crew to stop work on those dates.',
            ],
            [
                'name' => 'Aircon Overhaul (3 Units) - Mercado Residence',
                'client' => 'leonardo.mercado',
                'types' => [self::AIRCON_REPAIR, self::AIRCON_CLEANING],
                'scenario' => 'overdue',
                'description' => 'Full overhaul of three split-type units: pull-out cleaning, blower and motor bearing replacement and capacitor checks.',
                'quotation' => 27500,
                'created' => -20,
                'ranges' => [[-15, -12]],
                'target' => 16,
                'technicians' => 1,
                'delay' => 'Replacement blower motors for two units are back-ordered from the supplier.',
            ],

            // ---------------------------------------------------------------
            // Ongoing
            // ---------------------------------------------------------------
            [
                'name' => 'Factory Office Aircon Installation - Pacific Crest Logistics',
                'client' => 'fernando.co',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'ongoing',
                'description' => 'Supply and installation of a VRF system with one 20 HP outdoor unit and twelve ceiling cassette indoor units for the new two-storey office block.',
                'quotation' => 1320000,
                'incident' => ['title' => 'Damaged Unit on Delivery', 'description' => 'One ceiling cassette arrived with a cracked drain pan. It was set aside and the supplier is sending a replacement. The other units were not affected.'],
                'created' => -20,
                'ranges' => [[-5, 22]],
                'target' => 30,
                'technicians' => 2,
            ],
            [
                'name' => 'Exhaust and Fresh Air Ducting - Southwoods Medical Clinic Annex',
                'client' => 'teresa.villareal',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION, self::HEATING_VENTILATION],
                'scenario' => 'ongoing',
                'description' => 'Fresh-air supply and exhaust ducting for the clinic annex, including a negative-pressure isolation room, to meet the Department of Health air change requirements.',
                'quotation' => 890000,
                'created' => -18,
                'ranges' => [[-4, 20]],
                'target' => 28,
                'technicians' => 2,
            ],
            [
                'name' => 'Aircon Installation (3 Units) - Castro Residence',
                'client' => 'imelda.castro',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'ongoing',
                'description' => 'Installation of three 1.5 HP inverter split-type units for the bedrooms. Piping rough-in is done now and the units will be mounted once the client\'s painting works are finished.',
                'quotation' => 168000,
                'created' => -12,
                'ranges' => [[-3, -1], [15, 17]],
                'target' => 22,
                'technicians' => 1,
            ],
            [
                'name' => 'Ballroom HVAC Upgrade - Calamba Bay Hotel',
                'client' => 'rosario.herrera',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION, self::AIRCON_INSTALLATION],
                'scenario' => 'ongoing',
                'description' => 'Replacement of the ballroom\'s ageing package units with two 25 TR ducted units, new insulated ducting and a zoned controller so the room can be split for smaller events.',
                'quotation' => 2350000,
                'incident' => ['title' => 'Limited Ceiling Access', 'description' => 'An evening event in the ballroom meant the ceiling access panels had to stay closed after 3 PM. The crew moved to the morning shift for the rest of the week.'],
                'created' => -16,
                'ranges' => [[-2, 33]],
                'target' => 40,
                'technicians' => 2,
            ],
            [
                'name' => 'Kitchen Ventilation Hood and Ducting - Vergara Residence',
                'client' => 'gregorio.vergara',
                'types' => [self::HEATING_VENTILATION, self::DUCTING_INSTALLATION],
                'scenario' => 'ongoing',
                'description' => 'Installation of a range hood with ducting to the outside wall for the newly renovated kitchen. Ducting is being installed now; the hood will be mounted after the cabinets are delivered.',
                'quotation' => 64000,
                'created' => -9,
                'ranges' => [[-1, 0], [15, 16]],
                'target' => 20,
                'technicians' => 1,
            ],

            // ---------------------------------------------------------------
            // Awaiting Client Confirmation
            // ---------------------------------------------------------------
            [
                'name' => 'Split-Type Installation (2 Units) - Roxas Residence',
                'client' => 'analyn.roxas',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'awaiting',
                'description' => 'Installation of two 1.0 HP inverter split-type units in the children\'s bedrooms.',
                'quotation' => 96000,
                'created' => -18,
                'ranges' => [[-11, -10]],
                'target' => 5,
                'technicians' => 1,
            ],
            [
                'name' => 'Chiller Line Repair - Carmona Fresh Foods',
                'client' => 'antonio.saldana',
                'types' => [self::AIRCON_REPAIR],
                'scenario' => 'awaiting',
                'description' => 'Repair of the leaking chilled-water line serving the packing area, with replacement of two corroded valves and new pipe insulation.',
                'quotation' => 78000,
                'incident' => ['title' => 'Water Seepage at Valve Pit', 'description' => 'Water was seeping near the valve pit while the old valves were being removed. The area was cordoned off and dried out before the new insulation was applied.'],
                'created' => -14,
                'ranges' => [[-9, -7]],
                'target' => 3,
                'technicians' => 2,
            ],
            [
                'name' => 'Aircon Cleaning (6 Units) - Gutierrez Residence',
                'client' => 'emmanuel.gutierrez',
                'types' => [self::AIRCON_CLEANING],
                'scenario' => 'awaiting',
                'description' => 'Semi-annual cleaning of six split-type units, including the multi-split system installed earlier this year.',
                'quotation' => 10800,
                'created' => -6,
                'ranges' => [[-3, -3]],
                'target' => 2,
                'technicians' => 1,
            ],
            [
                'name' => 'Classroom Ventilation Fans - Bright Minds Learning Center',
                'client' => 'josephine.alcantara',
                'types' => [self::HEATING_VENTILATION],
                'scenario' => 'awaiting',
                'description' => 'Installation of wall-mounted ventilation fans and exhaust grilles in six ground-floor classrooms.',
                'quotation' => 148000,
                'created' => -25,
                'ranges' => [[-14, -6]],
                'target' => 4,
                'technicians' => 2,
            ],

            // ---------------------------------------------------------------
            // Pending - booked, not started
            // ---------------------------------------------------------------
            [
                'name' => 'Condominium Aircon Installation - Salcedo Residence',
                'client' => 'ferdinand.salcedo',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'pending',
                'description' => 'Installation of a 1.0 HP inverter split-type unit in the study room, with the outdoor unit on the condominium\'s designated ledge.',
                'quotation' => 54500,
                'created' => -7,
                'ranges' => [[16, 17]],
                'target' => 22,
                'technicians' => 1,
                'phases' => true,
            ],
            [
                'name' => 'Gym Aircon Preventive Maintenance - Sta. Rosa Fitness Hub',
                'client' => 'mark.javier',
                'types' => [self::AIRCON_CLEANING],
                'scenario' => 'pending',
                'description' => 'Quarterly preventive maintenance of the two ducted units serving the workout floor and the four wall-mounted units in the offices.',
                'quotation' => 38000,
                'created' => -10,
                'ranges' => [[18, 19]],
                'target' => 24,
                'technicians' => 2,
                'phases' => true,
            ],
            [
                'name' => 'Executive Floor Ducting (7th Floor) - Greenfield Business Suites',
                'client' => 'patricia.yap',
                'types' => [self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION],
                'scenario' => 'pending',
                'description' => 'Ducting works for the executive floor fit-out: supply, return and fresh-air ducting with acoustic lining near the board room.',
                'quotation' => 960000,
                'created' => -14,
                'ranges' => [[21, 38]],
                'target' => 45,
                'technicians' => 3,
                'phases' => true,
            ],
            [
                'name' => 'Second Floor Aircon Installation - Padilla Residence',
                'client' => 'rowena.padilla',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'pending',
                'description' => 'Installation of a 1.5 HP inverter split-type unit for the new second-floor family room.',
                'quotation' => 74000,
                'created' => -5,
                'ranges' => [[22, 23]],
                'target' => 28,
                'technicians' => 1,
                'phases' => false,
            ],
            [
                'name' => 'Range Hood and Exhaust Ducting - Abella Residence',
                'client' => 'josefina.abella',
                'types' => [self::DUCTING_INSTALLATION, self::HEATING_VENTILATION],
                'scenario' => 'pending',
                'description' => 'Exhaust ducting from the new range hood to the side wall with a backdraft damper and a weatherproof hood.',
                'quotation' => 32500,
                'created' => -4,
                'ranges' => [[25, 26]],
                'target' => 30,
                'technicians' => 1,
                'phases' => false,
            ],
            [
                'name' => 'Aircon Repair and Cleaning - Fuentes Residence',
                'client' => 'dennis.fuentes',
                'types' => [self::AIRCON_REPAIR, self::AIRCON_CLEANING],
                'scenario' => 'pending',
                'description' => 'Repair of a leaking drain pan on the bedroom unit and general cleaning of all three units.',
                'quotation' => 8900,
                'created' => -3,
                'ranges' => [[17, 17]],
                'target' => 20,
                'technicians' => 1,
                'phases' => true,
            ],
            [
                'name' => 'Ducted Aircon Installation - Tan Residence',
                'client' => 'charmaine.tan',
                'types' => [self::AIRCON_INSTALLATION, self::DUCTING_INSTALLATION],
                'scenario' => 'pending',
                'description' => 'A 3.0 TR ducted unit for the open-plan living, dining and kitchen area, with concealed ducting and linear diffusers.',
                'quotation' => 245000,
                'created' => -11,
                'ranges' => [[28, 33]],
                'target' => 38,
                'technicians' => 2,
                'phases' => true,
            ],
            [
                'name' => 'Inverter Aircon Installation (4 Units) - Bustamante Residence',
                'client' => 'wilfredo.bustamante',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'pending',
                'description' => 'Supply and installation of four 1.0 HP inverter split-type units for the bedrooms of a newly turned-over house.',
                'quotation' => 212000,
                'created' => -8,
                'ranges' => [[30, 32]],
                'target' => 36,
                'technicians' => 2,
                'phases' => false,
            ],

            // ---------------------------------------------------------------
            // Unscheduled - accepted, dates not set yet
            // ---------------------------------------------------------------
            [
                'name' => 'Branch Kitchen Exhaust System - Kusina ni Lola (Tagaytay)',
                'client' => 'melanie.tiongson',
                'types' => [self::HEATING_VENTILATION, self::DUCTING_FABRICATION],
                'scenario' => 'unscheduled',
                'description' => 'Kitchen hood exhaust and make-up air for the new Tagaytay branch, following the same design as the Silang kitchen. Dates are set once the branch\'s building permit is released.',
                'address' => 'Aguinaldo Highway, Brgy. Maharlika West, Tagaytay City, Cavite',
                'quotation' => 820000,
                'created' => -2,
                'ranges' => [],
                'target' => 60,
                'technicians' => 2,
                'new' => true,
            ],
            [
                'name' => 'Aircon Cleaning (3 Units) - Sarmiento Residence',
                'client' => 'maricel.sarmiento',
                'types' => [self::AIRCON_CLEANING],
                'scenario' => 'unscheduled',
                'description' => 'General cleaning of the three first-floor split-type units. The client will confirm a Saturday that suits the household.',
                'quotation' => 4800,
                'created' => -1,
                'ranges' => [],
                'target' => 30,
                'technicians' => 1,
                'new' => true,
            ],
            [
                'name' => 'Living Room Aircon Replacement - Mercado Residence',
                'client' => 'leonardo.mercado',
                'types' => [self::AIRCON_INSTALLATION],
                'scenario' => 'unscheduled',
                'description' => 'Replacement of the living room\'s 2.0 HP unit with a 2.5 HP inverter unit. Waiting for the client to choose between the two quoted brands.',
                'quotation' => 68000,
                'created' => -4,
                'ranges' => [],
                'target' => 35,
                'technicians' => 1,
                'new' => false,
            ],
            [
                'name' => 'Attic Ventilation Installation - Vergara Residence',
                'client' => 'gregorio.vergara',
                'types' => [self::HEATING_VENTILATION],
                'scenario' => 'unscheduled',
                'description' => 'Two solar-powered attic exhaust fans and soffit vents to bring down second-floor heat. To be scheduled after the kitchen ventilation project is finished.',
                'quotation' => 45000,
                'created' => -6,
                'ranges' => [],
                'target' => 40,
                'technicians' => 1,
                'new' => false,
            ],
        ];
    }

    /**
     * What each kind of job involves, for its phases, tasks, quotation and
     * reports. A project's first type decides which kind it is.
     *
     * @return array<string, array{phases: array<int, array{title: string, description: string, tasks: array<int, array{0: string, 1: string}>}>, quotation: array<int, array{0: string, 1: float}>, findings: array<int, string>, recommendation: string, summary: string, remarks: string, photos: array<int, string>, progress: array<int, string>}>
     */
    public static function workProfiles(): array
    {
        return [
            'installation' => [
                'phases' => [
                    ['title' => 'Site Preparation', 'description' => 'Confirm unit locations, protect finishes and prepare the wall penetrations.', 'tasks' => [
                        ['Site survey and layout marking', 'Confirm the indoor and outdoor unit locations with the client and mark the bracket, sleeve and drain positions.'],
                        ['Core drilling and wall sleeves', 'Drill the refrigerant and drain line penetrations and fit PVC sleeves.'],
                    ]],
                    ['title' => 'Installation', 'description' => 'Mount the units and run the piping, drain and power.', 'tasks' => [
                        ['Mount indoor and outdoor units', 'Install the mounting plates and outdoor brackets, then set and level the units.'],
                        ['Refrigerant piping and insulation', 'Run, flare and insulate the copper lines, then route the condensate drain with the correct fall.'],
                        ['Electrical termination', 'Pull the power and control cables and terminate them on a dedicated breaker.'],
                    ]],
                    ['title' => 'Testing', 'description' => 'Pressure test, vacuum, charge and check performance.', 'tasks' => [
                        ['Pressure test and vacuum', 'Hold a nitrogen pressure test for 30 minutes, then evacuate the lines to below 500 microns.'],
                        ['Commissioning and performance check', 'Release the charge, check suction pressure and running amps, and record supply-air temperatures.'],
                    ]],
                    ['title' => 'Final Inspection', 'description' => 'Walk the client through the system and clean up.', 'tasks' => [
                        ['Client walkthrough and turnover', 'Show the client the remote, filter cleaning and warranty terms, then clean up the work area.'],
                    ]],
                ],
                'quotation' => [
                    ['Supply of air-conditioning unit(s) as specified', 0.58],
                    ['Installation materials: copper tubing, insulation, drain line, brackets and wiring', 0.14],
                    ['Labor: mounting, piping and electrical termination', 0.20],
                    ['Testing, commissioning and hauling of debris', 0.08],
                ],
                'findings' => [
                    'The wall can take the mounting brackets without reinforcement.',
                    'The existing panel board has a spare slot for a dedicated breaker.',
                    'Outdoor unit location has enough clearance for airflow and servicing.',
                ],
                'recommendation' => 'Use inverter units sized for the measured room loads, with piping runs kept under 7 meters where possible.',
                'summary' => 'All units are installed, pressure-tested, vacuumed and commissioned. Supply-air temperatures and running amps are within the manufacturer\'s range.',
                'remarks' => 'The client was shown how to clean the filters. The first free cleaning is due in six months.',
                'photos' => ['outdoor-unit-installation.jpg', 'indoor-unit-inspection.jpg', 'front-panel-removal.jpg', 'indoor-unit-servicing.jpg'],
                'progress' => [
                    'Mounting plates and outdoor brackets are installed. Piping rough-in is complete.',
                    'Units are mounted and piping is insulated. Electrical termination is ongoing.',
                    'Pressure test held for 30 minutes with no drop. Vacuum and charging are next.',
                ],
            ],
            'ducting' => [
                'phases' => [
                    ['title' => 'Site Preparation', 'description' => 'Verify measurements against the plans and set out the duct routes.', 'tasks' => [
                        ['As-built measurement and duct layout', 'Take ceiling heights and obstructions and mark the trunk and branch routes on site.'],
                        ['Shop fabrication of duct sections', 'Fabricate the GI duct sections, fittings and transitions to the approved shop drawings.'],
                    ]],
                    ['title' => 'Installation', 'description' => 'Hang and seal the ducting and fit the terminals.', 'tasks' => [
                        ['Install hangers and supports', 'Install threaded rod hangers and angle bar supports at the required spacing.'],
                        ['Hang and seal trunk and branch ducts', 'Hang the duct sections, seal the joints with mastic and apply the thermal insulation.'],
                        ['Fit dampers, diffusers and grilles', 'Install volume dampers and connect the diffusers and grilles with flexible ducts.'],
                    ]],
                    ['title' => 'Testing', 'description' => 'Check for leaks and balance the airflow.', 'tasks' => [
                        ['Leak check and airflow balancing', 'Check the joints for leakage, then measure and balance the airflow at each outlet.'],
                    ]],
                    ['title' => 'Final Inspection', 'description' => 'Walk through with the client and hand over.', 'tasks' => [
                        ['Final walkthrough and as-built handover', 'Walk the client through the system and hand over the as-built drawings and balancing report.'],
                    ]],
                ],
                'quotation' => [
                    ['GI sheets, fittings and duct accessories', 0.34],
                    ['Thermal insulation, mastic and sealing materials', 0.12],
                    ['Diffusers, grilles and volume dampers', 0.14],
                    ['Labor: fabrication and installation', 0.32],
                    ['Testing, balancing and as-built drawings', 0.08],
                ],
                'findings' => [
                    'The ceiling space has enough clearance for the main trunk line.',
                    'Existing beam penetrations can be used for the branch ducts.',
                    'Access panels are needed near the volume dampers.',
                ],
                'recommendation' => 'Use insulated galvanized iron ducting with mastic-sealed joints, and add volume dampers on every branch for balancing.',
                'summary' => 'Ducting is installed, sealed and insulated. Airflow was balanced at every outlet to within 10% of design.',
                'remarks' => 'As-built drawings and the balancing report were handed over to the client.',
                'photos' => ['indoor-unit-inspection.jpg', 'outdoor-unit-installation.jpg', 'front-panel-removal.jpg', 'indoor-unit-servicing.jpg'],
                'progress' => [
                    'Hangers are installed along the main corridor. The first trunk sections are hung.',
                    'Branch ducts to the north rooms are installed and sealed. Insulation is ongoing.',
                    'Diffusers are connected in the completed zones. Balancing is scheduled next.',
                ],
            ],
            'ventilation' => [
                'phases' => [
                    ['title' => 'Site Preparation', 'description' => 'Confirm fan positions, openings and structural supports.', 'tasks' => [
                        ['Survey fan and opening locations', 'Confirm fan, louver and intake positions with the client and check the structural supports.'],
                        ['Prepare openings and supports', 'Cut the wall and roof openings and install the fan bases and curbs.'],
                    ]],
                    ['title' => 'Installation', 'description' => 'Install the fans, ducting and controls.', 'tasks' => [
                        ['Install exhaust and supply fans', 'Set the fans on their bases, fit the vibration isolators and weatherproof the openings.'],
                        ['Connect ducting and hoods', 'Connect the exhaust ducting to the hoods and fans with sealed, fire-rated joints where needed.'],
                        ['Wire fan controls', 'Wire the fan starters, speed controllers and wall switches.'],
                    ]],
                    ['title' => 'Testing', 'description' => 'Run the fans and measure airflow.', 'tasks' => [
                        ['Airflow and noise test', 'Run every fan and measure its airflow, rotation and noise level against the design values.'],
                    ]],
                    ['title' => 'Final Inspection', 'description' => 'Walk through with the client and hand over.', 'tasks' => [
                        ['Turnover and maintenance briefing', 'Walk the client through the controls and brief their staff on cleaning and maintenance.'],
                    ]],
                ],
                'quotation' => [
                    ['Supply of exhaust / supply fans and controls', 0.42],
                    ['Ducting, hoods, louvers and accessories', 0.22],
                    ['Labor: installation and electrical works', 0.28],
                    ['Testing, balancing and hauling of debris', 0.08],
                ],
                'findings' => [
                    'Measured air changes are well below the recommended rate for the space.',
                    'The roof structure can carry the fan curbs without reinforcement.',
                    'Existing openings can be reused for two of the intake louvers.',
                ],
                'recommendation' => 'Mechanical exhaust with matching filtered make-up air, sized for the recommended air changes per hour.',
                'summary' => 'All fans are installed and running. Measured airflow meets the design air changes per hour.',
                'remarks' => 'Staff were briefed on filter cleaning and the fan switching schedule.',
                'photos' => ['outdoor-unit-installation.jpg', 'indoor-unit-inspection.jpg', 'indoor-unit-servicing.jpg', 'front-panel-removal.jpg'],
                'progress' => [
                    'Roof curbs and fan bases are installed. Openings are weatherproofed.',
                    'Exhaust fans are set and the ducting to the hoods is connected.',
                    'Fan controls are wired. Initial test run done on two fans.',
                ],
            ],
            'cleaning' => [
                'phases' => [
                    ['title' => 'Site Preparation', 'description' => 'Protect the area and check the units before cleaning.', 'tasks' => [
                        ['Pre-cleaning inspection', 'Check each unit\'s operation, note existing faults and protect the walls and furniture.'],
                    ]],
                    ['title' => 'Cleaning and Servicing', 'description' => 'Clean the coils, blowers, filters and drains.', 'tasks' => [
                        ['Indoor unit cleaning', 'Wash the evaporator coils and blower wheels and clean the filters and drain pans.'],
                        ['Outdoor unit cleaning', 'Wash the condenser coils and check the fan blades and electrical connections.'],
                    ]],
                    ['title' => 'Testing', 'description' => 'Check performance after cleaning.', 'tasks' => [
                        ['Post-cleaning performance check', 'Run each unit, check drainage and record supply-air temperatures and running amps.'],
                    ]],
                    ['title' => 'Final Inspection', 'description' => 'Turn over to the client.', 'tasks' => [
                        ['Service report and turnover', 'Go over the service report with the client and recommend the next cleaning date.'],
                    ]],
                ],
                'quotation' => [
                    ['Labor: general cleaning per unit', 0.70],
                    ['Cleaning chemicals and consumables', 0.18],
                    ['Performance check and service report', 0.12],
                ],
                'findings' => [
                    'Filters and evaporator coils are heavily clogged with dust.',
                    'Drain lines show slow flow on some units.',
                    'No refrigerant leaks were found during the visual inspection.',
                ],
                'recommendation' => 'General cleaning of all units, with cleaning every six months after this.',
                'summary' => 'All units were cleaned and tested. Drainage is clear and supply-air temperatures are back to normal.',
                'remarks' => 'Next cleaning is recommended in six months.',
                'photos' => ['indoor-unit-servicing.jpg', 'front-panel-removal.jpg', 'indoor-unit-inspection.jpg', 'outdoor-unit-installation.jpg'],
                'progress' => [
                    'Indoor units are cleaned. Outdoor units are next.',
                ],
            ],
            'repair' => [
                'phases' => [
                    ['title' => 'Diagnosis', 'description' => 'Find the fault and confirm the repair with the client.', 'tasks' => [
                        ['Troubleshooting and fault diagnosis', 'Check the electrical components, pressures and controls to find the cause of the fault.'],
                    ]],
                    ['title' => 'Repair Works', 'description' => 'Replace the faulty parts and restore the system.', 'tasks' => [
                        ['Replace faulty components', 'Replace the parts found faulty and repair any refrigerant or drain leaks.'],
                        ['Recharge and restore operation', 'Pressure test, vacuum and recharge the system where the circuit was opened.'],
                    ]],
                    ['title' => 'Testing', 'description' => 'Run the system and check that the fault is fixed.', 'tasks' => [
                        ['Operational test', 'Run the system under load and check pressures, amps and temperatures.'],
                    ]],
                    ['title' => 'Final Inspection', 'description' => 'Turn over to the client.', 'tasks' => [
                        ['Repair report and turnover', 'Explain the repair to the client and hand over the replaced parts and warranty.'],
                    ]],
                ],
                'quotation' => [
                    ['Replacement parts as diagnosed', 0.45],
                    ['Refrigerant and consumables', 0.15],
                    ['Labor: troubleshooting and repair', 0.32],
                    ['Testing and service report', 0.08],
                ],
                'findings' => [
                    'The fault is consistent with failed electrical components on the unit.',
                    'Refrigerant pressure is below the normal operating range.',
                    'The compressor windings test within normal resistance.',
                ],
                'recommendation' => 'Replace the faulty parts, then pressure test and recharge the system to the nameplate charge.',
                'summary' => 'The faulty parts were replaced and the system was recharged. The units run normally under load.',
                'remarks' => 'Replaced parts are covered by a 90-day service warranty.',
                'photos' => ['front-panel-removal.jpg', 'indoor-unit-servicing.jpg', 'outdoor-unit-installation.jpg', 'indoor-unit-inspection.jpg'],
                'progress' => [
                    'Fault confirmed. Replacement parts were ordered and are expected tomorrow.',
                ],
            ],
        ];
    }

    /**
     * Which work profile a project's first type uses.
     */
    public static function kindOf(string $type): string
    {
        return match ($type) {
            self::AIRCON_CLEANING => 'cleaning',
            self::AIRCON_REPAIR => 'repair',
            self::DUCTING_FABRICATION, self::DUCTING_INSTALLATION => 'ducting',
            self::HEATING_VENTILATION => 'ventilation',
            default => 'installation',
        };
    }
}
