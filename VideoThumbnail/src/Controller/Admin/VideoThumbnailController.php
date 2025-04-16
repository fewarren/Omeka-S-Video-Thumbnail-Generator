<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;

class VideoThumbnailController extends AbstractActionController
{
    protected $entityManager;
    protected $fileManager;
    protected $settings;

    public function __construct($entityManager, $fileManager)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }

    public function indexAction()
    {
        $form = new ConfigBatchForm();
        $form->init();

        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        if (!is_array($supportedFormats)) {
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        $form->setData([
            'default_frame_position' => $this->settings->get('videothumbnail_default_frame', 10),
            'supported_formats' => $supportedFormats,
        ]);

        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                $this->messenger()->addSuccess('Video thumbnail settings updated.');

                if (!empty($formData['regenerate_thumbnails'])) {
                    $dispatcher = $this->jobDispatcher();
                    $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames', [
                        'frame_position' => $formData['default_frame_position'],
                    ]);

                    $message = new Message(
                        'Regenerating video thumbnails in the background (job %s). This may take a while.',
                        $job->getId()
                    );
                    $this->messenger()->addSuccess($message);
                }

                return $this->redirect()->toRoute('admin/video-thumbnail');
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('totalVideos', $this->getTotalVideos());
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        return $view;
    }

    protected function getTotalVideos()
    {
        // Assuming you have access to the entity manager to query the database
        $repository = $this->entityManager->getRepository('Omeka\Entity\Media');
        
        // Query for the total number of videos based on supported formats
        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        $queryBuilder = $repository->createQueryBuilder('media');
        $queryBuilder->select('COUNT(media.id)')
                     ->where($queryBuilder->expr()->in('media.mediaType', ':formats'))
                     ->setParameter('formats', $supportedFormats);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    public function saveFrameAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $mediaId = $this->params()->fromPost('media_id');
        $position =

