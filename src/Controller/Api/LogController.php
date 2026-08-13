<?php

namespace App\Controller\Api;

use App\Service\ActivityLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class LogController extends AbstractController
{
  // Pour lister le journal d'activité
  #[Route('/logs', name: 'logs_list', methods: ['GET'])]
  #[IsGranted('ROLE_ADMIN')]
  public function list(ActivityLogger $logger): JsonResponse
  {
    return $this->json([
      'logs' => $logger->findAll()
    ], 200);
  }
}
