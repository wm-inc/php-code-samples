<?php

namespace AppBundle\Admin;

use AppBundle\Entity\Promotions;
use AppBundle\Repository\ImagesRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Datagrid\DatagridMapper;
use Sonata\AdminBundle\Datagrid\ListMapper;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Route\DefaultRouteGenerator;
use Sonata\AdminBundle\Show\ShowMapper;
use Sonata\CoreBundle\Form\Type\EqualType;
use Sonata\DoctrineORMAdminBundle\Model\ModelManager;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use AppBundle\Service\ImageService;
use AppBundle\Entity\Images;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sonata\AdminBundle\Form\Type\ChoiceFieldMaskType;
use Sonata\AdminBundle\Route\RouteCollection;
use Sonata\AdminBundle\Form\Type\ModelAutocompleteType;
use Symfony\Component\HttpFoundation\Session\Session;
use Knp\Menu\ItemInterface as MenuItemInterface;
use Sonata\AdminBundle\Admin\AdminInterface;

class PromotionsAdmin extends AbstractAdmin
{
    use LoggerAwareTrait;
    use LoggerTrait;

    /*
    // Code For Re-Order The Listing
    protected $datagridValues = [
        '_page' => 1,
        '_sort_order' => 'ASC',
        '_sort_by' => 'position',
    ];
    */
    // Used to customize the name of the route responded to by this admin.
    protected $baseRouteName = 'admin_promotion';
    // Used to customize the URL generated for links in this admin.
    protected $baseRoutePattern = 'marketing/promotions';

    protected $datagridValues = [
        '_sort_order' => 'DESC',
        '_sort_by' => 'id',
    ];

    /**
     * @var ImageService
     */
    private $imageService;
    /**
     * @var Images
     */
    private $imagesEntity;

    /**
     * Promotions constructor.
     * @param string $code
     * @param string $class
     * @param string $baseControllerName
     * @param ImageService $imageService
     * @param Images $imagesEntity
     * @param LoggerInterface $logger
     */
    public function __construct(
        string $code,
        string $class,
        string $baseControllerName,
        ImageService $imageService,
        Images $imagesEntity,
        LoggerInterface $logger
    )
    {
        parent::__construct($code, $class, $baseControllerName);
        $this->setImageService($imageService);
        $this->setImagesEntity($imagesEntity);
        $this->setLogger($logger);
    }

    /**
     * @param RouteCollection $collection
     */
    protected function configureRoutes(RouteCollection $collection)
    {
        parent::configureRoutes($collection);
        //$collection->add('move', $this->getRouterIdParameter().'/move/{position}');
    }

    /**
     * @param array $filterValues
     */
    public function configureDefaultFilterValues(array &$filterValues)
    {
        parent::configureDefaultFilterValues($filterValues);
        $filterValues['ends'] = [
            'type' => EqualType::TYPE_IS_EQUAL,
            'value' => '2018-12-31',
        ];
    }

    /**
     * @param Promotions $object
     * @return bool
     */
    public function savePdf($object = null)
    {
        $this->info(__METHOD__ . '/BEGIN');
        $returnValue = false;
        $uploadedFile = $object->getPdfFile();
        if ($uploadedFile instanceof UploadedFile) {
            $fileName = $uploadedFile->getClientOriginalName();
            $this->info(__METHOD__ . '/PDF filename: "' . $fileName . '"');
            $pdfFile = $uploadedFile->openFile('rb');
            $object->setPdf($pdfFile->fread($pdfFile->getSize()));
            $object->setPdfName($fileName);
        } else {
            $this->info(__METHOD__ . '/END (No PDF available to save)');
        }

        return $returnValue;
    }

    /**
     * @param Promotions $promotion
     * @param Images|null $image
     */
    protected function saveHomePageImage(Promotions $promotion, Images $image = null)
    {
        $this->info(__METHOD__ . '/BEGIN');
        $parentFolder = 'homepage';
        $file = $promotion->getImageHomePageFile();
        $imageEntity = $this->storeImage($promotion, $file, $parentFolder, $image);
        $this->saveImage($imageEntity);
        $promotion->setImageHomePage($imageEntity);
        $this->savePromotion($promotion);
        $this->info(__METHOD__ . '/END');
    }

    /**
     * @param Promotions $promotion
     * @param Images|null $image
     */
    protected function saveSpecialsPageImage(Promotions $promotion, Images $image = null)
    {
        $this->info(__METHOD__ . '/BEGIN');
        $parentFolder = 'special';
        $file = $promotion->getImageSpecialsPageFile();
        $imageEntity = $this->storeImage($promotion, $file, $parentFolder, $image);
        $this->saveImage($imageEntity);
        $promotion->setImageSpecialsPage($imageEntity);
        $this->savePromotion($promotion);
        $this->info(__METHOD__ . '/END');
    }

    /**
     * @param Promotions $promotion
     * @param Images|null $image
     */
    protected function saveEbayImage(Promotions $promotion, Images $image = null)
    {
        $this->info(__METHOD__ . '/BEGIN');
        $parentFolder = 'ebay';
        $file = $promotion->getImageEbayFile();
        $imageEntity = $this->storeImage($promotion, $file, $parentFolder, $image);
        $this->saveImage($imageEntity);
        $promotion->setImageEbay($imageEntity);
        $this->savePromotion($promotion);
        $this->info(__METHOD__ . '/END');
    }

    /**
     * Stores the image in AWS S3 and returns an Images entity to be associated with the promotion.
     * @param Promotions $promotion
     * @param UploadedFile $file
     * @param $parentFolder
     * @param Images|null $imageEntity
     * @return Images|null
     */
    protected function storeImage(
        Promotions $promotion,
        UploadedFile $file,
        $parentFolder,
        Images $imageEntity = null
    ): ?Images {
        $promotionId = !empty($promotion->getId()) ? $promotion->getId() : $this->getNextPromotionId();
        $fileName = $file->getClientOriginalName();
        $basePath = $this->getRequest()->server->get('DOCUMENT_ROOT');
        $file->move($basePath . '/tmp/', $fileName);
        $imageSource = $basePath . '/tmp/' . $fileName;

        $this->imageService->initializeImage(
            0,
            0,
            5000,
            1000000,
            ['image/png', 'image/jpg', 'image/png', 'image/jpg', 'image/jpeg', 'image/JPEG'],
            $imageSource,
            $imageSource
        );
        $output = $this->imageService->validate();

        if ($this->imageService->isSuccess()) {
            $imageName = $promotionId.'-'.$fileName;
            $this->imageService->saveToS3($parentFolder.'/'.$imageName, $imageSource);
            $this->imageService->saveToS3($parentFolder.'/thumbnail/'.$imageName, $imageSource);

            list($width, $height) = getimagesize($imageSource);
            $imageEntity = !is_null($imageEntity) ? $imageEntity : new Images();
            $imageEntity->setName($fileName);
            $imageEntity->setType($file->getClientMimeType());
            $imageEntity->setWidth($width);
            $imageEntity->setHeight($height);
            $attr = "width='$width' height ='$height'";
            $imageEntity->setAttr($attr);
            $imageEntity->setSize($file->getClientSize());
            return $imageEntity;
        } else {
            $message = implode('<br>', $output);
            /**
             * @var Session $session
             */
            $session = $this->getRequest()->getSession();
            $session->getFlashBag()->add("danger", $message);
            if ($this->isCurrentRoute('create')) {
                $redirection = new RedirectResponse($this->getConfigurationPool()
                    ->getContainer()
                    ->get('router')
                    ->generate($this->baseRouteName . '_create'));
            } else {
                $redirection = new RedirectResponse($this->getConfigurationPool()
                    ->getContainer()
                    ->get('router')
                    ->generate($this->baseRouteName . '_edit', ['id' => $promotion->getId()]));
            }
            $redirection->send();
            exit();
        }
    }

    /**
     * @param null $string
     * @return null|string|string[]
     */
    protected function slugIt($string = null)
    {
        $string = strtolower($string);
        $string = preg_replace('/\//i', '-', $string);
        $string = preg_replace('/[^a-z0-9_\.]/i', '-', $string);
        $string = preg_replace('/--/i', '', $string);
        return $string;
    }

    /**
     * @return int
     */
    protected function getNextImageId()
    {
        $em = $this->getEntityManager();
        /**
         * @var ImagesRepository $repository
         */
        $repository = $em->getRepository('AppBundle:Images');

        return $repository->getNextId();
    }

    /**
     * @return int
     */
    protected function getNextPromotionId()
    {
        $em = $this->getEntityManager();
        /**
         * @var ImagesRepository $repository
         */
        $repository = $em->getRepository('AppBundle:Promotions');

        return $repository->getNextId();
    }

    /**
     * @param DatagridMapper $datagridMapper
     */
    protected function configureDatagridFilters(DatagridMapper $datagridMapper)
    {
        $datagridMapper
            ->add('begins')
            ->add('ends')
            ->add('title')
            ->add('shortTitle')
            ->add('subTitle')
            ->add('rebatePrice')
            ->add('rebateText')
            ->add('rebateSubtext')
            ->add('teaser');
    }

    /**
     * @param ListMapper $listMapper
     */
    protected function configureListFields(ListMapper $listMapper)
    {
        $listMapper
            ->addIdentifier(
                'title',
                null,
                [
                    'template' => 'AppBundle::promotionListThumb.html.twig',
                    'header_class' => 'col-md-1'
                ]
            )
            ->add('begins')
            ->add('ends')
            ->add(
                'status',
                null,
                [
                    'template' => 'AppBundle::promotionListStatus.html.twig',
                    'header_class' => 'col-md-1'
                ]
            )->add('_action', null, [
                'header_class' => 'col-md-1',
                'actions' => [
                    'Load' => [
                        'template' => 'AppBundle::promotionBulkLoadProductsButton.html.twig'
                    ],
                    'UnLoad' => [
                        'template' => 'AppBundle::promotionBulkUnLoadProductsButton.html.twig'
                    ]
                ]
            ]);
    }

    /**
     * @param FormMapper $formMapper
     */
    protected function configureFormFields(FormMapper $formMapper)
    {
        if (method_exists('Symfony\Component\Form\AbstractType', 'getBlockPrefix')) {
            $datePickerType = 'Sonata\CoreBundle\Form\Type\DatePickerType';
        } else {
            $datePickerType = 'sonata_type_date_picker';
        }

        //get current images urls
        /**
         * @var Promotions $promotion
         */
        $promotion = $this->getSubject();
        $promotionUrl = $promotion->getUrl();
        $imageHomePage = $promotion->getImageHomePage();
        $imageHomePageUrl = '';
        $imageSpecialsPageUrl = '';
        $imageEbayUrl = '';
        if (!is_null($imageHomePage) && $imageHomePage instanceof Images) {
            $imageHomePageUrl = $promotion->getImageUrl(Promotions::PROMOTION_IMAGE_TYPE_HOMEPAGE);
        }
        $imageSpecialPage = $promotion->getImageSpecialsPage();
        if (!is_null($imageSpecialPage) && $imageSpecialPage instanceof Images) {
            $imageSpecialsPageUrl = $promotion->getImageUrl(Promotions::PROMOTION_IMAGE_TYPE_SPECIAL);
        }

        $imageEbay = $promotion->getImageEbay();
        if (!is_null($imageEbay) && $imageEbay instanceof Images) {
            $imageEbayUrl = $promotion->getImageUrl(Promotions::PROMOTION_IMAGE_TYPE_EBAY);
        }

        //creating help text for Home Page Image
        $ImageHomePage = '*Requirements: Format: .jpg<br>';
        if ($imageHomePageUrl != '') {
            $ImageHomePage .= '<img alt="' . $promotion->getTitle() . '" src="' . $imageHomePageUrl . '" style="width:100px;height:36px">';
        }
        //creating help text for Specials Page Image
        $imageSpecialPage = '*Requirements: Format: .jpg<br>';
        if ($imageSpecialsPageUrl != '') {
            $imageSpecialPage .= '<img alt="' . $promotion->getTitle() . '" src="' . $imageSpecialsPageUrl . '" style="width:100px;height:36px">';
        }

        //creating help text for Ebay Image
        $imageEbayText = '*Requirements: Format: .jpg<br>';
        if ($imageEbayUrl != '') {
            $imageEbayText .= '<img alt="' . $promotion->getTitle() . '" src="' . $imageEbayUrl . '" style="width:100px;height:36px">';
        }

        //creating help text for Pdf
        $pdfName = $promotion->getPdfName() ? $promotion->getPdfName() :
            '*Requirements: Upload valid Pdf Document';

        parent::configureFormFields($formMapper);
        $formMapper
            ->tab('Promotion')
            ->with('Basic Info', array('class' => 'col-md-7'))
            ->add('title', null, ['help' => '[Do not use comma (,) in name]'])
            ->add('shortTitle', null, ['required' => false, 'help' => '[Short Title (optional) [Do not use comma (,) in name]'])
            ->add('showTitleOnHomePage', null, [
                'required' => false,
                'label' => 'Show Title On Home Page'
            ])
            ->add('teaser', null, ['required' => false, 'attr' => ['rows' => 6]])
            ->end()
            ->with('Show on Home/Special Page/Google Feed', array('class' => 'col-md-5'))
            ->add('showOnBrandPage', null, [
                'required' => false,
                'label' => 'Show on Brand Pages'
            ])
            ->add('showOnHomePage', null, [
                'required' => false,
                'label' => 'Include in Promotions featured on the home page'
            ])
            ->add('showOnSpecialsPage', null, [
                'required' => false,
                'label' => 'Show On Site (Catalog, SKU, Promotion)'
            ])
            ->add('linkToGoogleFeed', null, [
                'required' => false,
                'label' => 'Link to Google Feed'
            ])
            ->add('applyOnlyMapProducts', null, [
                'required' => false,
                'label' => 'Apply Only Map Products'
            ])
            ->add('promoType', 'choice', [
                'choices' => [
                    Promotions::PROMOTYPE_REBATE => Promotions::PROMOTYPE_REBATE,
                    Promotions::PROMOTYPE_SALE => Promotions::PROMOTYPE_SALE,
                    Promotions::PROMOTYPE_OFFER => Promotions::PROMOTYPE_OFFER,
                    Promotions::PROMOTYPE_MEMBERS_ONLY => Promotions::PROMOTYPE_MEMBERS_ONLY
                ]
            ])
            ->add('displayArea', ChoiceFieldMaskType::class, [
                'choices' => [
                    Promotions::DISPLAYAREA_RETAIL => Promotions::DISPLAYAREA_RETAIL,
                    Promotions::DISPLAYAREA_WHOLESALE => Promotions::DISPLAYAREA_WHOLESALE
                ],
                'map' => [
                    Promotions::DISPLAYAREA_RETAIL => '',
                    Promotions::DISPLAYAREA_WHOLESALE => ['wholesale'],
                ],
                'placeholder' => 'Choose an option',
                'required' => true
            ])
            ->add('wholesale')
            ->end()
            ->end()
            ->tab('Period')
            ->with('From and To ', array('class' => 'col-md-12'))
            ->add(
                'begins',
                $datePickerType,
                [
                    'required' => false,
                    'label' => 'Begins'
                ]
            )
            ->add(
                'ends',
                $datePickerType,
                [
                    'required' => false,
                    'label' => 'Ends'
                ]
            )
            ->end()
            ->end();
        $formMapper
            ->tab('Images')
            ->with('Rotating images should all have the same dimensions');
        if ($this->isCurrentRoute('edit')) {
            $formMapper
                ->add(
                    'imageHomePageFile',
                    FileType::class,
                    [
                        'required' => false,
                        'help' => $ImageHomePage,
                        'label' => 'Home Page Image'
                    ]
                )
                ->add(
                    'imageSpecialsPageFile',
                    FileType::class,
                    [
                        'required' => false,
                        'help' => $imageSpecialPage,
                        'label' => 'Specials Page Image'
                    ]
                )->add(
                    'imageEbayFile',
                    FileType::class,
                    [
                        'required' => false,
                        'help' => $imageEbayText,
                        'label' => 'Ebay Image'
                    ]
                );
        } else {
            $formMapper
                ->add(
                    'imageHomePageFile',
                    FileType::class,
                    [
                        'required' => false,
                        'help' => $ImageHomePage,
                        'label' => 'Home Page Image'
                    ]
                )
                ->add(
                    'imageSpecialsPageFile',
                    FileType::class,
                    [
                        'required' => false,
                        'help' => $imageSpecialPage,
                        'label' => 'Specials Page Image'
                    ]
                )->add(
                    'imageEbayFile',
                    FileType::class,
                    [
                        'required' => false,
                        'help' => $imageEbayText,
                        'label' => 'Ebay Image'
                    ]
                );
        }
        $formMapper
            ->end()
            ->end()
            ->tab('Link To')
            ->with('Rebate PDF', ['class' => 'col-md-4'])
            ->add('pdfFile', FileType::class, [
                'required' => false,
                'label' => 'PDF',
                'help' => $pdfName,
                'data_class' => null
            ])
            ->end()
            ->with('Link Promotion To', ['class' => 'col-md-4'])
            ->add('linkTo', ChoiceFieldMaskType::class, [
                'choices' => [
                    Promotions::LINKTO_SPECIALSPAGEIMAGE => Promotions::LINKTO_SPECIALSPAGEIMAGE,
                    Promotions::LINKTO_URL => Promotions::LINKTO_URL,
                    Promotions::LINKTO_PDF => Promotions::LINKTO_PDF,
                    Promotions::LINKTO_PRODUCTS => Promotions::LINKTO_PRODUCTS,
                    Promotions::LINKTO_NOTHING => Promotions::LINKTO_NOTHING,
                ],
                'map' => [
                    Promotions::LINKTO_URL => ['url'],
                    Promotions::LINKTO_PDF => ['pdf'],
                    Promotions::LINKTO_PRODUCTS => [
                        // Parcell indicated Rebate Icon Image is not used and can be removed.
                        //                        'rebateIconImageFile',
                        'rebateText',
                        'rebateTermsLinkText',
                        'rebateTerms',
                        'manufacturers',
                        'excludeManufacturers',
                        'excludeProductSubTypes',
                        'excludeCategories',
                        'excludeProductLines',
                        'excludeProducts',
                        'productSubTypes',
                        'categories',
                        'productLines',
                        'products'
                    ],
                ],
                'required' => true
            ])
            ->add('url', null, [
                'label' => 'URL',
                'required' => false,
                'help' => 'Note: This URL can be absolute (in which case it should begin with "http://"),
                                 or relative (to the site) (in which case it should begin with "/").',
                'data_class' => null
            ])
            ->add('rebateText', null, ['required' => false])
            ->add('rebateTermsLinkText', null, ['required' => false])
            ->add('rebateTerms', null, ['attr' => ['rows' => 6], 'required' => false])
            ->end();
        //Enable "flag products on promotion" only for Link to products in edit action
        if (!$this->isCurrentRoute('add')) {
            $formMapper
                ->with('Flag Products on Promotion', ['class' => 'col-md-4'])
                ->add('manufacturers')
                ->add('productSubTypes')
                ->add('categories')
                ->add('productLines', ModelAutocompleteType::class, [
                    'property' => 'name',
                    'multiple' => true,
                    'required' => false,
                    'callback' => function ($admin, $property, $value) {
                        /**
                         * @var PromotionsAdmin $admin
                         */
                        $datagrid = $admin->getDatagrid();
                        $queryBuilder = $datagrid->getQuery();
                        /**
                         * @var QueryBuilder $queryBuilder
                         */

                        $datagrid->setValue($property, null, $value);
                        $rootAliases = $queryBuilder->getRootAliases();
                        $rootAlias = $rootAliases[0];
                        $queryBuilder
                            ->andWhere($rootAlias . '.name LIKE :productLinesArg')
                            ->setParameter('productLinesArg', '%' . $value . '%')
                            ->orderBy($rootAlias . '.name');
                    },
                ])
                ->end()
                ->with('Exclude Product From Promotion', ['class' => 'col-md-4'])
                ->add('excludeManufacturers')
                ->add('excludeProductSubTypes')
                ->add('excludeCategories')
                ->add('excludeProductLines', ModelAutocompleteType::class, [
                    'property' => 'name',
                    'multiple' => true,
                    'required' => false,
                    'callback' => function ($admin, $property, $value) {
                        /**
                         * @var PromotionsAdmin $admin
                         */
                        $datagrid = $admin->getDatagrid();
                        $queryBuilder = $datagrid->getQuery();
                        /**
                         * @var QueryBuilder $queryBuilder
                         */

                        $datagrid->setValue($property, null, $value);
                        $rootAliases = $queryBuilder->getRootAliases();
                        $rootAlias = $rootAliases[0];
                        $queryBuilder
                            ->andWhere($rootAlias . '.name LIKE :productLinesArg')
                            ->setParameter('productLinesArg', '%' . $value . '%')
                            ->orderBy($rootAlias . '.name');
                    },
                ])
                ->add('excludeProducts', ModelAutocompleteType::class, [
                    'property' => 'partNumber',
                    'multiple' => true,
                    'required' => false,
                    'callback' => function ($admin, $property, $value) {
                        /**
                         * @var PromotionsAdmin $admin
                         */
                        $datagrid = $admin->getDatagrid();
                        /**
                         * @var QueryBuilder $queryBuilder
                         */
                        $queryBuilder = $datagrid->getQuery();

                        $datagrid->setValue($property, null, $value);
                        $rootAliases = $queryBuilder->getRootAliases();
                        $rootAlias = $rootAliases[0];
                        $queryBuilder
                            ->andWhere($rootAlias . '.partNumber LIKE :productArg')
                            ->setParameter('productArg', '%' . $value . '%')
                            ->orderBy($rootAlias . '.partNumber');
                    },
                ])
                ->end();
        }
        $formMapper
            ->end()
            ->tab('Change History')
            ->with('Change History', ['class' => 'col-md-6'])
            ->add('created', $datePickerType, [
                'attr' => ['readonly' => true]
            ])
            ->add('modified', $datePickerType, [
                'attr' => ['readonly' => true]
            ])
            ->add('updated', $datePickerType, [
                'attr' => ['readonly' => true]
            ])
            ->add('deleted', null, [
                'attr' => ['readonly' => true]
            ])
            ->add('deletedBy', null, [
                'attr' => ['readonly' => true]
            ])
            ->add('deletedDate', $datePickerType, [
                'attr' => ['readonly' => true]
            ])
            ->end()
            ->end();
    }

    /**
     * @param ShowMapper $showMapper
     */
    protected function configureShowFields(ShowMapper $showMapper)
    {
        $showMapper
            ->add('id')
            ->add('created')
            ->add('modified')
            ->add('updated')
            ->add('begins')
            ->add('ends')
            ->add('title')
            ->add('shortTitle')
            ->add('subTitle')
            ->add('rebatePrice')
            ->add('rebateText')
            ->add('rebateSubtext')
            ->add('teaser')
            ->add('imageListPageId')
            ->add('imageSpecialsPage')
            ->add('imageHomePage')
            ->add('imageEbay')
            ->add('imageGlobalBannerId')
            ->add('showOnHomePage')
            ->add('showOnSpecialsPage')
            ->add('slug')
            ->add('deleted')
            ->add('deletedBy')
            ->add('deletedDate')
            ->add('url')
            ->add('linkTo');
        $showMapper
            ->add('pdfName')
            ->add('pdf')
            ->add('mailInRebate')
            ->add('showTitleOnHomePage')
            ->add('rebateTerms')
            ->add('rebateTermsLinkText')
            ->add('rebateLinkText')
//            ->add('imageRebateIcon')
            ->add('contentsCategoryId')
            ->add('iframe')
            ->add('promoCode')
            ->add('promoAmount')
            ->add('promoAmountType')
            ->add('promoApplyTo')
            ->add('promoMinQty')
            ->add('promotionTypes')
            ->add('promotionTypess')
            ->add('promotionTypesService')
            ->add('promotionTypesGoodyear')
            ->add('promotionTypesTsn')
            ->add('promotionTypesCta')
            ->add('buttonPrint')
            ->add('buttonEmail')
            ->add('featured')
            ->add('cBuilderimg')
            ->add('linkToGoogleFeed');
        $showMapper
            ->add('promoType')
            ->add('promotionsTypeProductSpecifications');
    }

    /**
     * @param string|null $class
     * @return EntityManager
     */
    protected function getEntityManager(?string $class = null)
    {
        /**
         * @var ModelManager $modelManager
         */
        $modelManager = $this->getModelManager();
        if (is_null($class)) {
            $class = Promotions::class;
        }
        $em = $modelManager->getEntityManager($class);

        return $em;
    }

    /**
     * @param $ImgService
     */
    public function setImageService($ImgService)
    {
        $this->imageService = $ImgService;
    }

    /**
     * @return ImageService
     */
    public function getImageService()
    {
        return $this->imageService;
    }

    /**
     * @param $imagesEntity
     */
    public function setImagesEntity($imagesEntity)
    {
        $this->imagesEntity = $imagesEntity;
    }

    /**
     * @return Images
     */
    public function getImagesEntity()
    {
        return $this->imagesEntity;
    }

    /**
     * @param  Images $image
     * @return \DateTime
     */
    public function getS3UpdateDate($image)
    {
        return $image->getS3UpdateDate();
    }

    /**
     * @param Promotions $object
     */
    public function prePersist($object)
    {
        parent::prePersist($object);
        // Save pdf and rebate icon image if promotion link to  is products
        if (!is_null($object->getPdfFile())) {
            $this->info(__METHOD__ . '/saving PDF.');
            // $this->setImagesEntity($pdfImage);
            $this->savePdf($object);
            //$object->setPdf($this->getImagesEntity());
            $this->info(__METHOD__ . '/DONE saving PDF.');
        }
    }

    /**
     * @param Promotions $object
     */
    public function postPersist($object)
    {
        $this->info(__METHOD__ . '/BEGIN');
        parent::postPersist($object);
        $promotionRepository = $this->getEntityManager()->getRepository(Promotions::class);
        $promotionRepository->setLogger($this->logger);
        $promotionRepository->saveLegacyPromotionProductAssociations($object);

        $emptyImage = $this->getImagesEntity();
        $clonedImage = clone $emptyImage;
        //$pdfImage = clone $emptyImage;
        //$rebateIconImage = clone $emptyImage;

        if (is_null($object->getRebateText())) {
            $object->setRebateText('');
        }

        // Save Home page image
        $object->setSlug($this->slugIt($object->getTitle()));

        if (!is_null($object->getImageHomePageFile())) {
            $this->info(__METHOD__ . '/saving HOME page image.');
            $this->saveHomePageImage($object);
            $this->info(__METHOD__ . '/DONE saving HOME page image.');
        }

        // Save the specials page image
        if (!is_null($object->getImageSpecialsPageFile())) {
            $this->info(__METHOD__ . '/saving SPECIALS page image.');
            $this->setImagesEntity($clonedImage);
            $this->saveSpecialsPageImage($object);
            $this->info(__METHOD__ . '/DONE saving SPECIALS page image.');
        }

        // Save the Ebay page image
        if (!is_null($object->getImageEbayFile())) {
            $this->info(__METHOD__ . '/saving Ebay image.');
            $this->setImagesEntity($clonedImage);
            $this->saveEbayImage($object);
            $this->info(__METHOD__ . '/DONE saving Ebay image.');
        }

        $em = $this->getEntityManager();

        try {
            $this->info(__METHOD__ . '/Persisting promotion...');
            $em->persist($object);
            $this->info(__METHOD__ . '/Flushing entity manager...');
            $this->info(__METHOD__ . '/DONE saving promotion');
        } catch (ORMException $orme) {
            $this->error(
                __METHOD__ . '/Exception caught: ' . $orme->getMessage()
            );
        }
        $this->info(__METHOD__ . '/END');
    }

    /**
     * @param Promotions $object
     * @throws \Exception
     */
    public function preUpdate($object)
    {
        parent::preUpdate($object);
        if (!is_null($object->getPdfFile())) {
            $this->info(__METHOD__ . '/saving PDF.');
            $this->savePdf($object);
            $this->info(__METHOD__ . '/DONE saving PDF.');
        }
        $object->setModified(new \DateTime());
        $object->setUpdated(new \DateTime());
    }

    /**
     * @param Promotions $object
     */
    public function postUpdate($object)
    {
        $this->info(__METHOD__ . '/BEGIN');
        parent::postUpdate($object);
        $promotionRepository = $this->getEntityManager()->getRepository(Promotions::class);
        $promotionRepository->setLogger($this->logger);
        $this->info(__METHOD__ . '/saveLegacyPromotionProductAssociations ...');
        $promotionRepository->saveLegacyPromotionProductAssociations($object);
        $this->info(__METHOD__ . '/DONE saveLegacyPromotionProductAssociations');
        if (!is_null($object->getImageHomePageFile())) {
            $this->info(__METHOD__ . '/saving HOME page image.');
            $this->saveHomePageImage($object, $object->getImageHomePage());
            $this->setImagesEntity($object->getImageHomePage());
            $this->info(__METHOD__ . '/DONE saving HOME page image.');
        }

        if (!is_null($object->getImageSpecialsPageFile())) {
            $this->info(__METHOD__ . '/saving SPECIALS page image.');
            $this->saveSpecialsPageImage($object, $object->getImageSpecialsPage());
            $this->setImagesEntity($object->getImageSpecialsPage());
            $this->info(__METHOD__ . '/DONE saving SPECIALS page image.');
        }

        if (!is_null($object->getImageEbayFile())) {
            $this->info(__METHOD__ . '/saving Ebay image.');
            $this->saveEbayImage($object, $object->getImageEbay());
            $this->setImagesEntity($object->getImageEbay());
            $this->info(__METHOD__ . '/DONE saving Ebay image.');
        }
        $this->info(__METHOD__ . '/END');
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        $this->logger->log($level, $message, $context);
    }

    /**
     * Persist changes to a Promotion entity.
     * @param Promotions $promotion
     */
    private function savePromotion(Promotions $promotion)
    {
        $this->info(__METHOD__ . '/BEGIN');
        $this->saveEntity($promotion);
        $this->info(__METHOD__ . '/END');
    }

    private function saveImage(Images $image)
    {
        $this->info(__METHOD__ . '/BEGIN');
        $this->saveEntity($image);
        $this->info(__METHOD__ . '/END');
    }

    /**
     * @param mixed $entity
     */
    private function saveEntity($entity): void
    {
        /**
         * @var EntityManager $em
         */
        $em = $this->getEntityManager();
        try {
            $em->persist($entity);
            $em->flush($entity);
        } catch (OptimisticLockException $e) {
            $this->error(
                __METHOD__ . '/Exception: ' . $e->getMessage()
            );
        } catch (ORMException $e) {
            $this->error(
                __METHOD__ . '/Exception: ' . $e->getMessage()
            );
        }
    }

    public function getBatchActions()
    {
        // retrieve the default (currently only the delete action) actions
        $actions = parent::getBatchActions();
        // check user permissions
        if ($this->hasRoute('edit') && $this->isGranted('EDIT')) {
            $actions['clonePromotion'] = [
                'label' => $this->trans('Clone', array(), 'SonataAdminBundle'),
                'ask_confirmation' => true // If true, a confirmation will be asked before performing the action
            ];
        }
        return $actions;
    }


    /**
     * @param MenuItemInterface $menu
     * @param $action
     * @param AdminInterface|null $childAdmin
     * @return mixed|void
     */
    public function configureSideMenu(MenuItemInterface $menu, $action, AdminInterface $childAdmin = null)
    {
        if ($this->isGranted('EDIT') && $this->isCurrentRoute('edit')) {
            $admin = $this->isChild() ? $this->getParent() : $this;
            $promotionId = $admin->getRequest()->get('id');
            $promotionRepository = $this->getEntityManager()->getRepository(Promotions::class);
            $count = $promotionRepository->getPromotionProductsCount($promotionId);
            $menu->addChild(
                'Bulk Load Products',
                [
                    'uri' => $this->getRouteGenerator()->generate(
                        'app.promotion.bulk.product-associations',
                        ['promotionId' => $promotionId]
                    )
                ]
            );
            $menu->addChild(
                'Bulk UnLoad Products',
                [
                    'uri' => $this->getRouteGenerator()->generate(
                        'app.promotion.bulk.product-disassociations',
                        ['promotionId' => $promotionId]
                    )
                ]
            );

            $menu->addChild("View All Products ({$count})", [
                'uri' => $this->getRouteGenerator()->generate(
                    'admin_promotion_admin_promotion_products_list',
                    [
                        'id' => $promotionId,
                    ]
                )
            ]);
        }
    }
}
