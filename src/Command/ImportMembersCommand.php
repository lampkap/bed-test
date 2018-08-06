<?php

namespace App\Command;

use App\Controller\MemberController;
use App\Entity\Member;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ImportMembersCommand extends ContainerAwareCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'import:members';

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('arg1');

        if ($file) {
            // check if file exists
            if(file_exists($file)) {
                $info = pathinfo($file);
                // check if file is op type csv
                if($info['extension'] === 'csv') {
                    // everything checks out so let's import
                    $this->importMembers($file, $io);
                } else {
                    $io->error('Het bestand moet van het type CSV zijn');
                }
            } else {
                $io->error(sprintf('Er werd geen bestand %s gevonden', $file));
            }
        } else {
            $io->error('Geef een bestandspad mee als argument');
        }

        if ($input->getOption('option1')) {
            // ...
        }
    }

    /**
     * @param $file
     * @param SymfonyStyle $io
     */
    protected function importMembers($file, SymfonyStyle $io)
    {
        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        // reformat the values of the csv to an array
        $members = $this->parseCsv($file);
        if(!$members) {
            $io->error('Het csv bestand kon niet worden verwerkt of heeft geen inhoud');
            exit;
        }
        // set import status values
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $i = 0;
        $max = count($members);

        // loop through all csv records
        foreach($members as $memberValues) {
            // check if the date is configurable
            $date = MemberController::formatBirthdate($memberValues[0]);
            if(!$date) {
                // if not, we stop the import
                $io->error('De datum ' . $memberValues[0] . ' is niet correct. Het importeren is onderbroken');
                exit;
            } else {
                // check if a member exists with a specific member number
                $member = $em->getRepository(Member::class)->findBy(array('number' => $memberValues[1]));
                // if the member does not exist, we create one
                if(empty($member)) {

                    $this->createMember($date, $memberValues[1]);

                    //$io->writeln('Lid ' . $memberValues[1] . ', geboren op ' . $date->format('d-m-Y') . ' geïmporteerd');
                    $created++;
                // else we check if we need to update the member
                } else {
                    // check if the birthdate has changed of a member
                    $hasUpdated = $this->updateMember($member, $date);
                    // if it has, he will be updated
                    if($hasUpdated) {
                        //$io->writeln('Lid ' . $memberValues[1] . ', geboren op ' . $date->format('d-m-Y') . ' geüpdate');
                        $updated++;
                    // else he will be skipped
                    } else {
                        //$io->writeln('Lid ' . $memberValues[1] . ' is niet gewijzigd.');
                        $skipped++;
                    }

                }
            }

            $i++;

            if($i >= $max || $i % 500 === 0) {
                $em->flush();
            }
        }

        $io->success('Import doorgevoerd!');
        $io->table(array('Aangemaakt', 'Aangepast', 'Overgeslagen'), array(array($created, $updated, $skipped)));

    }

    /**
     * @param $file
     * @return array|bool
     */
    protected function parseCsv($file)
    {
        $data = array();
        if (($handle = fopen($file, 'r')) !== FALSE) {
            while (($row = fgetcsv($handle, 0, ';')) !== FALSE) {
                $data[] = $row;
            }
            fclose($handle);
        }

        return (empty($data)) ? false : $data;
    }

    /**
     * @param $date
     * @param $number
     */
    protected function createMember($date, $number)
    {
        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        $member = new Member();

        $member->setBirthdate($date);
        $member->setNumber($number);

        $em->persist($member);
    }

    /**
     * @param $member
     * @param $date
     * @return bool
     */
    protected function updateMember($member, $date)
    {
        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        $member = reset($member);

        if($member->getBirthdate() != $date) {
            $member->setBirthdate($date);

            $em->persist($member);

            // return true to say the member could and has been updated
            return true;

        } else {
            // return false to say the member is not updated since the values did not change
            return false;
        }
    }
}
