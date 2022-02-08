<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\RebrickableClient;
use App\Entity\Piece;
use App\Entity\PieceCount;
use App\Entity\PieceNumber;
use App\Repository\ColorRepository;
use App\Repository\PieceListRepository;
use App\Repository\PieceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:part-lists:import')]
class ImportPartListsCommand extends Command
{
    public function __construct(
        private RebrickableClient $rebrickableClient,
        private EntityManagerInterface $entityManager,
        private PieceListRepository $pieceListRepository,
        private PieceRepository $pieceRepository,
        private ColorRepository $colorRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->pieceListRepository->findBy(['needImport' => true]) as $pieceList) {
            $partListResponse = $this->rebrickableClient->getPartsOfPartList($pieceList->getRebrickableListId());

            foreach ($partListResponse['results'] as $partListEntry) {
                $piece = $this->pieceRepository->findPart($partListEntry['part']['part_num'], $partListEntry['color']['id']);
                if ($piece === null) {
                    $piece = new Piece();
                    $piece
                        ->setPartNumber($partListEntry['part']['part_num'])
                        ->setColor($this->colorRepository->find($partListEntry['color']['id']))
                        ->setName($partListEntry['part']['name'])
                    ;

                    $colorResponse = $this->rebrickableClient->getPartColor($piece->getPartNumber(), $piece->getColor()->getId());
                    $piece->setImageUrl($colorResponse['part_img_url']);

                    foreach ($partListEntry['part']['external_ids'] as $system => $externalIdResponse) {
                        $pieceNumber = new PieceNumber();
                        $pieceNumber
                            ->setSystem($system)
                            ->setIds($externalIdResponse)
                        ;
                        $piece->addExternalId($pieceNumber);
                    }

                    $this->entityManager->persist($piece);
                }

                $count = $piece->getCountByPieceList($pieceList);
                if ($count === null) {
                    $count = new PieceCount();
                    $count->setList($pieceList);
                    $count->setPiece($piece);
                    $piece->addList($count);
                }

                $count->setCountNeeded($partListEntry['quantity']);

                $piece->updateCache();
            }

            $this->entityManager->flush();
        }

        return self::SUCCESS;
    }
}
