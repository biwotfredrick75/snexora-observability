<?php

namespace Database\Seeders;

use App\Models\HrLetterTemplate;
use Illuminate\Database\Seeder;

class HrLetterTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name'     => 'Offer Letter',
                'category' => 'offer',
                'body'     => "Dear {{full_name}},\n\n"
                    . "We are pleased to offer you the position of {{job_title}} in the {{department}} department at {{company_name}}, "
                    . "effective {{hire_date}}. Your basic salary will be KES {{basic_salary}} per month.\n\n"
                    . "Please sign and return a copy of this letter to confirm your acceptance.\n\n"
                    . "Yours sincerely,\n{{company_name}} HR Department\n{{date}}",
            ],
            [
                'name'     => 'Employment Confirmation Letter',
                'category' => 'confirmation',
                'body'     => "Dear {{full_name}},\n\n"
                    . "This letter confirms that you, employee number {{emp_no}}, have successfully completed your probationary period "
                    . "and are now a confirmed employee of {{company_name}} in the role of {{job_title}}, {{department}} department.\n\n"
                    . "Congratulations on your confirmation.\n\n"
                    . "Yours sincerely,\n{{company_name}} HR Department\n{{date}}",
            ],
            [
                'name'     => 'Salary Certificate',
                'category' => 'certificate',
                'body'     => "TO WHOM IT MAY CONCERN\n\n"
                    . "This is to certify that {{full_name}}, employee number {{emp_no}}, is employed at {{company_name}} "
                    . "as {{job_title}} in the {{department}} department, with a current basic salary of KES {{basic_salary}} per month.\n\n"
                    . "This certificate is issued upon the employee's request.\n\n"
                    . "Yours sincerely,\n{{company_name}} HR Department\n{{date}}",
            ],
            [
                'name'     => 'Experience Letter',
                'category' => 'experience',
                'body'     => "TO WHOM IT MAY CONCERN\n\n"
                    . "This is to certify that {{full_name}} (employee number {{emp_no}}) was employed at {{company_name}} "
                    . "as {{job_title}} in the {{department}} department from {{hire_date}} until their date of departure.\n\n"
                    . "We found them to be a dedicated and hardworking member of staff and wish them well in their future endeavours.\n\n"
                    . "Yours sincerely,\n{{company_name}} HR Department\n{{date}}",
            ],
        ];

        foreach ($templates as $t) {
            HrLetterTemplate::firstOrCreate(['name' => $t['name']], $t);
        }
    }
}
