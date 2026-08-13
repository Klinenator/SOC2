<?php
/** Build the current-period workpaper register from the prior SOC 2 test table. */
$target = __DIR__ . '/../data/audit_tests.json';
if (file_exists($target) && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "audit_tests.json already exists; use --force to rebuild it.\n");
    exit(1);
}

$rows = <<<'ROWS'
CC1.1|Employee handbook, code of conduct, enforcement, and new-hire acknowledgment|on_event|no_events
CC1.1|Third-party contractual terms, conditions, and responsibilities|annual|no_exceptions
CC1.2|Independent board oversight and objective decision-making|annual|no_exceptions
CC1.3|Organizational structure, reporting lines, authority, and responsibility|annual|no_exceptions
CC1.4|Position descriptions and candidate skill evaluation|on_event|no_events
CC1.4|Role-based training and continuing competence|annual|no_exceptions
CC1.4|Employee, contractor, and vendor background checks|on_event|no_events
CC1.5|Employee performance reviews and control accountability|annual|no_exceptions
CC2.1|Infrastructure and endpoint logging and monitoring|monthly|no_exceptions
CC2.1|Security calendar and internal control communications|quarterly|no_exceptions
CC2.2|Security policies communicated and acknowledged during onboarding|on_event|no_events
CC2.2|Onboarding and quarterly security-awareness training|quarterly|no_exceptions
CC2.3|Customer responsibilities in subscription agreements|annual|no_exceptions
CC2.3|Customer communication for planned and emergency changes|on_event|no_events
CC3.1|Quarterly internal-control review|quarterly|no_exceptions
CC3.1|Management review of commitments and operating objectives|quarterly|no_exceptions
CC3.2|Annual risk assessment and risk treatment|annual|no_exceptions
CC3.2|System, software, data-flow, and organizational inventory|annual|no_exceptions
CC3.3|Fraud risk considered in risk assessment|annual|no_exceptions
CC3.4|Material changes identified and assessed|quarterly|no_exceptions
CC4.1|Capacity and performance monitoring|monthly|no_exceptions
CC4.1|Continuous security monitoring and alerting|monthly|no_exceptions
CC4.2|Planned changes and roadmap communicated to management|quarterly|no_exceptions
CC4.2|Quarterly control-owner self-evaluation and deficiency follow-up|quarterly|no_exceptions
CC5.1|Control activities derived from assessed risk|annual|no_exceptions
CC5.2|Technology controls assigned to risk owners|annual|no_exceptions
CC5.3|Employee sanction procedure in handbook|annual|no_exceptions
CC5.3|Annual information-security and governance policy review|annual|outside_period
CC6.1|Standard production build procedures and CIS baseline|annual|no_exceptions
CC6.1|Unique accounts, password requirements, and SSH public-key authentication|quarterly|no_exceptions
CC6.2|Management-authorized new-hire and access-change requests|on_event|no_events
CC6.2|HR-triggered termination and access-revocation tickets|on_event|no_events
CC6.3|Annual access-role and privilege review|annual|no_exceptions
CC6.4|Physical infrastructure controls performed by AWS|annual|not_applicable
CC6.5|Media sanitization and disposal procedures|on_event|no_events
CC6.6|Perimeter deny-by-default firewall rules|quarterly|no_exceptions
CC6.6|TLS encryption on web servers|quarterly|no_exceptions
CC6.7|VPN, TLS, and SFTP protection for data in transit|quarterly|no_exceptions
CC6.8|Centrally managed anti-malware coverage|monthly|no_exceptions
CC7.1|Monthly internal and external vulnerability scans and annual penetration test|monthly|outside_period
CC7.1|Critical vulnerability review, ownership, and remediation tracking|monthly|no_exceptions
CC7.2|Unauthorized file and configuration change detection|monthly|no_exceptions
CC7.3|Security-event impact review and remediation|weekly|no_exceptions
CC7.4|Incident-response policies and procedures|annual|no_exceptions
CC7.5|Incident recovery and contingency plans|annual|no_exceptions
CC8.1|Authorized production change requests|on_event|no_events
CC8.1|Separate development and production environments|annual|no_exceptions
CC8.1|Change-management policies and procedures|annual|no_exceptions
CC9.1|Business-continuity and disaster-recovery plan and test|annual|no_exceptions
CC9.2|AWS and key-vendor SOC report review|annual|no_exceptions
CC9.2|Vendor due diligence, contracts, and ongoing monitoring|annual|no_exceptions
C1.1|MFA for external application users|quarterly|no_exceptions
C1.1|TLS protection of confidential information|quarterly|no_exceptions
C1.1|Data-retention requirements|annual|no_exceptions
C1.1|Clean-desk requirements|annual|no_exceptions
C1.1|Data-classification policy|annual|no_exceptions
C1.1|Password policy|annual|no_exceptions
C1.1|Current network diagram|annual|no_exceptions
C1.1|Two-factor authentication for sensitive systems|quarterly|no_exceptions
C1.1|Production data restrictions in test environments|quarterly|no_exceptions
C1.1|Customer-data segregation|quarterly|no_exceptions
C1.1|New-hire access provisioned within required timeframe|on_event|no_events
C1.1|Role-based application access|quarterly|no_exceptions
C1.1|Password-protected application access|quarterly|no_exceptions
C1.1|Production access restricted to authorized users|quarterly|no_exceptions
C1.2|Customer-data deletion after contract termination|on_event|no_exceptions
C1.2|Electronic-media disposal procedures|on_event|no_exceptions
C1.2|Paper-record disposal procedures|on_event|no_exceptions
C1.2|Secure disposal-bin handling|annual|no_exceptions
C1.2|Termination checklist for return and disposal of assets|on_event|no_events
ROWS;

$tests = [];
$sequence = [];
foreach (preg_split('/\R/', trim($rows)) as $line) {
    [$controlId, $activity, $frequency, $priorResult] = explode('|', $line, 4);
    $sequence[$controlId] = ($sequence[$controlId] ?? 0) + 1;
    $tests[] = [
        'id' => sprintf('%s-%02d', $controlId, $sequence[$controlId]),
        'controlId' => $controlId,
        'activity' => $activity,
        'frequency' => $frequency,
        'priorProcedure' => 'Inspect records and configuration evidence to determine that ' . lcfirst($activity) . '.',
        'priorResult' => $priorResult,
        'populationSource' => '',
        'ownerId' => '',
        'status' => 'not_started',
        'sampleRefs' => [],
        'evidenceIds' => [],
        'conclusion' => '',
        'exception' => '',
        'updatedAt' => null,
    ];
}

file_put_contents($target, json_encode($tests, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Created ' . count($tests) . " workpapers.\n";
