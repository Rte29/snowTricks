<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\Figure;
use App\Entity\Category;
use App\Form\FigureType;
use Doctrine\ORM\EntityManager;
use App\Repository\MediaRepository;
use App\Repository\FigureRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class FigureController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $em, Request $request, PaginatorInterface $paginator): Response
    {
        $repo = $em->getRepository(Figure::class);
        $figures = $repo->findBy([], ['createdAt' => 'DESC']);

        $figuresAll = $paginator->paginate(
            $figures,
            $request->query->getInt('page', 1),
            6
        );

        return $this->render(
            'blog/home.html.twig',
            [
                'controler_name' => 'FigureController',
                'figures' => $figuresAll
            ]
        );
    }

    #[Route('/ajouter', name: 'app_add_figure')]
    #[Route('/modifier/{slug}', name: 'app_edit_figure')]
    public function form(Figure $figure = null, Request $request, EntityManagerInterface $manager, FigureRepository $figureRepo, MediaRepository $mediaRepo, CategoryRepository $categoryRepo, $slug = null): Response
    {

        if ($this->getUser() == null) {
            $this->addFlash('danger', 'Vous devez être connecté pour ajouter ou modifier une figure');
            return $this->redirectToRoute('app_home');
        }
        if (!$figure) {
            $figure = new Figure;
        }

        $user = $this->getUser();
        $groups = $categoryRepo->findAll();
        $groupsFigure = [];

        foreach ($groups as $group) {
            $groupsFigure[$group->getFigureCategory()] = $group->getFigureCategory();
        }
        $form = $this->createForm(FigureType::class, $figure);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //slug
            $title = $figure->getTitle();
            $slugger = new AsciiSlugger();
            $slug = strtolower($slugger->slug($title, '-'));
            $i = 0;

            do {
                $existSlug = $figureRepo->findOneBy([
                    'slug' => $slug
                ]);
                if ($existSlug != null) {
                    $slug = strtolower($slugger->slug($title, '-') . '-' . $i);
                    $i++;
                }
            } while ($existSlug != null);
            $figure->setSlug($slug);

            //add pictures
            $medias = $form->get('media')->getData();
            $oldMain = $mediaRepo->findOneBy(['main' => true, 'figure' => $figure->getId()]);
            $counter = 0;

            foreach ($medias as $media) {
                $fichier = md5(uniqid()) . '.' . $media->guessExtension();
                try {

                    $media->move(
                        $this->getParameter('figures_img_directory'),
                        $fichier
                    );
                } catch (FileException $e) {
                    //
                }

                $photo = new Media();
                if ($oldMain === null && $counter === 0) {
                    $photo->setMain(true);
                }
                $photo->setImage(true);
                $photo->setUrl($fichier);
                $figure->addMedium($photo);

                // main selector

            }

            if (!$figure->getId()) {
                $figure->setCreatedAt(new \DateTimeImmutable());
            } else {
                $figure->setUpdateAt(new \DateTimeImmutable());
            }
            $figure->setUser($user);

            if (!$figure->getId()) {
                $this->addFlash("success", "La figure a bien été ajouté");
            } else {
                $this->addFlash("success", "La modification de la figure a bien été prise en compte");
            }

            $manager->persist($figure);
            $manager->flush();
            $counter = $counter + 1;

            return $this->redirectToRoute('app_show_figure', ['slug' => $slug]);
        }
        return $this->render('figure/add_figure.html.twig', [
            'form' => $form->createView(),
            'editMode' => $figure->getId() !== null

        ]);
    }

    #[Route('/figures/editer/{slug}', name: 'app_show_figure')]
    public function show(EntityManagerInterface $em, Figure $figure, Media $media, $slug): Response
    {
        $repo = $em->getRepository(Figure::class);
        $figure = $repo->findOneBy(['slug' => $slug]);
        $repo = $em->getRepository(Media::class);
        $media = $repo->findAll();
        return $this->render('figure/show_figure.html.twig', [
            'figure' => $figure,
            'media' => $media
        ]);
    }
}
