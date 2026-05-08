<?php
namespace CleverSay;

defined('ABSPATH') || exit;

/**
 * Starter KB packs for new client provisioning.
 *
 * Each pack is a curated list of common question/answer pairs that give a
 * freshly-provisioned client something to test the chatbot with out of the
 * box. Clients are expected to edit/replace these — they're scaffolding,
 * not final content.
 *
 * Adding a new pack: add an entry to self::packs() with a unique slug,
 * a human label for the UI, and an array of entries with:
 *   - keyword      (main search keyword — e.g. 'tuition')
 *   - sub_keyword  (pattern modifier — blank or 'aadefault' for fallback)
 *   - question     (the canonical question phrasing)
 *   - response     (the answer text shown to users)
 */
class StarterKB {

    /**
     * Return all available packs keyed by slug.
     * @return array<string, array{label: string, description: string, entries: array}>
     */
    public static function packs(): array {
        return [
            'empty' => [
                'label'       => 'Empty — start from scratch',
                'description' => 'No pre-loaded entries. Add your own knowledge from the KB editor.',
                'entries'     => [],
            ],
            'admissions' => [
                'label'       => 'Admissions Office',
                'description' => 'Applications, deadlines, requirements, status checks, fees.',
                'entries'     => self::pack_admissions(),
            ],
            'dean_of_students' => [
                'label'       => 'Dean of Students',
                'description' => 'Student conduct, concerns, emergencies, resources.',
                'entries'     => self::pack_dean_of_students(),
            ],
            'registrar' => [
                'label'       => 'Registrar Office',
                'description' => 'Transcripts, enrollment verification, grades, schedule changes.',
                'entries'     => self::pack_registrar(),
            ],
        ];
    }

    private static function pack_admissions(): array {
        return [
            ['keyword' => 'apply',          'sub_keyword' => '',           'question' => 'How do I apply?',                              'response' => 'You can apply online through our application portal. Visit the admissions page on our website for the current application form, deadlines, and required documents.'],
            ['keyword' => 'deadline',       'sub_keyword' => 'aadefault',  'question' => 'What are the application deadlines?',          'response' => 'Application deadlines vary by semester and program. Priority deadlines are typically earlier than final deadlines. Please check our admissions website for the most current dates.'],
            ['keyword' => 'requirements',   'sub_keyword' => 'aadefault',  'question' => 'What are the admission requirements?',         'response' => 'Admission requirements generally include transcripts, test scores (where applicable), letters of recommendation, and an application essay. Requirements vary by program — check the admissions website for program-specific details.'],
            ['keyword' => 'status',         'sub_keyword' => '',           'question' => 'How can I check my application status?',       'response' => 'Log in to the applicant portal to check your current application status. You\'ll need the email address and password you used when applying.'],
            ['keyword' => 'fee',            'sub_keyword' => 'aadefault',  'question' => 'Is there an application fee?',                 'response' => 'Most programs require an application fee. The exact amount varies by program. Fee waivers may be available for eligible applicants — contact the admissions office for details.'],
            ['keyword' => 'transcript',     'sub_keyword' => 'aadefault',  'question' => 'How do I submit my transcripts?',              'response' => 'Official transcripts should be sent directly from your previous school to the admissions office. Electronic submission is typically preferred.'],
            ['keyword' => 'test',           'sub_keyword' => 'score',      'question' => 'Do you require test scores (SAT/ACT/GRE)?',    'response' => 'Test score requirements depend on your program and application type. Some programs are test-optional. Check program-specific requirements on our website.'],
            ['keyword' => 'tour',           'sub_keyword' => 'campus',     'question' => 'Can I schedule a campus tour?',                'response' => 'Yes — campus tours are available year-round. Visit our campus tours page to book a visit, join an info session, or take a virtual tour.'],
            ['keyword' => 'transfer',       'sub_keyword' => 'aadefault',  'question' => 'How do I transfer from another school?',       'response' => 'Transfer students apply through the same application portal. You\'ll need transcripts from all previous colleges. Transfer credit evaluation happens after admission.'],
            ['keyword' => 'international',  'sub_keyword' => 'aadefault',  'question' => 'I\'m an international student — what\'s the process?', 'response' => 'International applicants follow the standard application but also need to submit English proficiency scores (TOEFL/IELTS), financial documentation, and passport copies. Contact our international admissions team for guidance.'],
            ['keyword' => 'decision',       'sub_keyword' => 'when',       'question' => 'When will I get my admission decision?',       'response' => 'Decision timelines vary by program and application round. Most applicants hear back within 4-6 weeks of submitting a complete application.'],
            ['keyword' => 'accept',         'sub_keyword' => 'offer',      'question' => 'How do I accept my offer of admission?',       'response' => 'Accept your admission offer by logging into the applicant portal and submitting your enrollment deposit by the stated deadline.'],
            ['keyword' => 'financial',      'sub_keyword' => 'aid',        'question' => 'How do I apply for financial aid?',            'response' => 'Submit the FAFSA as early as possible. Our financial aid office will contact you once your aid package is ready. For scholarships, check our financial aid website.'],
            ['keyword' => 'visit',          'sub_keyword' => 'aadefault',  'question' => 'Can I visit the campus?',                      'response' => 'Yes — we welcome visitors. Schedule a tour, join an open house, or drop by our welcome center during business hours.'],
            ['keyword' => 'contact',        'sub_keyword' => 'aadefault',  'question' => 'How do I contact the admissions office?',      'response' => 'Our admissions office is happy to help. Please see the contact information on our admissions website for phone, email, and office hours.'],
        ];
    }

    private static function pack_dean_of_students(): array {
        return [
            ['keyword' => 'conduct',        'sub_keyword' => 'aadefault',  'question' => 'What is the student code of conduct?',         'response' => 'The student code of conduct outlines academic integrity, behavioral expectations, and the consequences of violations. The full policy is available on our Dean of Students website.'],
            ['keyword' => 'emergency',      'sub_keyword' => 'aadefault',  'question' => 'Who do I contact in an emergency?',            'response' => 'For immediate emergencies, call 911. For on-campus emergencies or safety concerns, contact campus safety. The Dean of Students office also provides support during non-emergency crises.'],
            ['keyword' => 'report',         'sub_keyword' => 'concern',    'question' => 'How do I report a concern about another student?', 'response' => 'You can submit a concern through our reporting system — anonymously if you prefer. The Dean of Students office reviews all reports and follows up confidentially.'],
            ['keyword' => 'counseling',     'sub_keyword' => '',           'question' => 'How do I access counseling services?',         'response' => 'Counseling services are available to all enrolled students. You can schedule an appointment online, call the counseling center, or walk in during urgent-need hours.'],
            ['keyword' => 'grievance',      'sub_keyword' => 'aadefault',  'question' => 'How do I file a grievance?',                   'response' => 'The grievance process begins with an informal conversation with the involved party, followed by a formal written complaint if needed. Contact our office for guidance on your specific situation.'],
            ['keyword' => 'absence',        'sub_keyword' => 'aadefault',  'question' => 'How do I notify faculty of an extended absence?', 'response' => 'For extended absences (3+ days) due to illness, family emergency, or other serious situations, the Dean of Students office can send a verified absence notification to your professors on your behalf.'],
            ['keyword' => 'academic',       'sub_keyword' => 'integrity',  'question' => 'What happens if I\'m accused of academic dishonesty?', 'response' => 'Academic integrity cases follow a review process where you can respond to the allegation. Contact our office for information about your rights and the procedure.'],
            ['keyword' => 'harassment',     'sub_keyword' => 'aadefault',  'question' => 'I\'m being harassed — what do I do?',         'response' => 'Your safety is our priority. Contact the Dean of Students office, Title IX coordinator, or campus safety directly. We can help you understand your options including formal complaints and protective measures.'],
            ['keyword' => 'accommodations', 'sub_keyword' => 'aadefault',  'question' => 'How do I request disability accommodations?',  'response' => 'Disability accommodations are coordinated through our disability services office. You\'ll need to register with them and provide documentation of your disability.'],
            ['keyword' => 'housing',        'sub_keyword' => 'aadefault',  'question' => 'I\'m having a housing problem. What do I do?', 'response' => 'For housing-related issues like roommate conflicts or maintenance, first contact your residence hall staff. If unresolved, the Dean of Students office can help mediate or connect you with housing administration.'],
            ['keyword' => 'leave',          'sub_keyword' => 'absence',    'question' => 'How do I take a leave of absence?',            'response' => 'Medical, personal, or military leaves of absence can be requested through the Dean of Students office. We\'ll coordinate with the registrar and financial aid on your behalf.'],
            ['keyword' => 'support',        'sub_keyword' => 'aadefault',  'question' => 'I\'m struggling — where can I get help?',       'response' => 'You\'re not alone. Our office works with counseling, health services, academic support, and financial aid to connect you with the right resources. Start by reaching out so we can help you find what you need.'],
        ];
    }

    private static function pack_registrar(): array {
        return [
            ['keyword' => 'transcript',     'sub_keyword' => 'request',    'question' => 'How do I request my transcript?',              'response' => 'Official transcripts can be requested through the registrar\'s website. Electronic transcripts are typically available within 1-2 business days; paper copies take longer.'],
            ['keyword' => 'transcript',     'sub_keyword' => 'fee',        'question' => 'Is there a fee for transcripts?',              'response' => 'Transcript fees vary by delivery type (electronic, paper, rush). Check the registrar\'s website for current pricing and payment options.'],
            ['keyword' => 'enrollment',     'sub_keyword' => 'verification','question' => 'How do I get an enrollment verification letter?', 'response' => 'Enrollment verification letters are available through your student portal or directly from the registrar. Most employers and insurance companies also accept the National Student Clearinghouse verification.'],
            ['keyword' => 'enrollment',     'sub_keyword' => 'aadefault',  'question' => 'How do I check my enrollment status?',         'response' => 'Log into your student portal to see your current enrollment status, credit hours, and registered courses.'],
            ['keyword' => 'grade',          'sub_keyword' => 'aadefault',  'question' => 'How do I see my grades?',                      'response' => 'Grades are posted in your student portal after the end of each term. Grade posting dates are on the registrar\'s academic calendar.'],
            ['keyword' => 'grade',          'sub_keyword' => 'change',     'question' => 'I think my grade is wrong. How do I appeal?',  'response' => 'Grade disputes begin with your instructor. If unresolved, a formal grade appeal can be filed through your department. The registrar\'s office processes approved grade changes.'],
            ['keyword' => 'register',       'sub_keyword' => 'aadefault',  'question' => 'How do I register for classes?',               'response' => 'Registration happens through your student portal. Check the registrar\'s academic calendar for your registration window and priority dates.'],
            ['keyword' => 'drop',           'sub_keyword' => 'class',      'question' => 'How do I drop a class?',                       'response' => 'You can drop a class through your student portal within the drop period. After that, you may need to withdraw, which could affect your transcript. Check the academic calendar for drop/withdraw deadlines.'],
            ['keyword' => 'withdraw',       'sub_keyword' => 'aadefault',  'question' => 'How do I withdraw from a course?',             'response' => 'Course withdrawal after the drop deadline results in a W grade on your transcript. Withdrawal deadlines are on the academic calendar. Contact the registrar if you need to withdraw from all courses.'],
            ['keyword' => 'diploma',        'sub_keyword' => 'aadefault',  'question' => 'When will I get my diploma?',                  'response' => 'Diplomas are typically mailed 6-8 weeks after your degree conferral date. Make sure your mailing address in the student portal is current.'],
            ['keyword' => 'graduation',     'sub_keyword' => 'apply',      'question' => 'How do I apply for graduation?',               'response' => 'Submit a graduation application through your student portal by the deadline for your intended graduation term. Your academic advisor can help verify you\'re on track.'],
            ['keyword' => 'major',          'sub_keyword' => 'change',     'question' => 'How do I change my major?',                    'response' => 'Major changes are processed through the registrar after approval from the new department. Meet with an advisor in your new major first to discuss degree requirements.'],
            ['keyword' => 'catalog',        'sub_keyword' => 'year',       'question' => 'Which catalog year applies to me?',            'response' => 'Your catalog year is typically the year you matriculated. It determines which degree requirements apply to you. Check your student portal or contact the registrar for your specific catalog year.'],
            ['keyword' => 'verification',   'sub_keyword' => 'aadefault',  'question' => 'I need an official letter. How do I request one?', 'response' => 'Most verification letters (enrollment, degree, dates attended) can be requested through the registrar\'s website. Processing time is usually 2-5 business days.'],
            ['keyword' => 'contact',        'sub_keyword' => 'aadefault',  'question' => 'How do I contact the registrar?',              'response' => 'Contact information and office hours are on the registrar\'s website. Many requests can be submitted online without needing to visit in person.'],
        ];
    }
}
