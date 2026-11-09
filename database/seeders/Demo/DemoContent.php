<?php

namespace Database\Seeders\Demo;

use App\Enums\Channel;
use App\Enums\PostStatus;

/**
 * Hand-written, clearly fictional content for DemoDataSeeder. Every animal,
 * person and event here is made up; none of it comes from real APES records.
 */
final class DemoContent
{
    /**
     * Posts per channel: [title, status]. APES CIC has more than 12 published
     * posts so its channel page paginates.
     *
     * @return array<string, list<array{0: string, 1: PostStatus}>>
     */
    public static function posts(): array
    {
        return [
            Channel::ApesCic->value => [
                ['Our year in numbers: rescues, rehoming and volunteers', PostStatus::Published],
                ['Welcoming two new trustees to the board', PostStatus::Published],
                ['How your donations kept the heat lamps on this winter', PostStatus::Published],
                ['Volunteer open day at the sanctuary', PostStatus::Published],
                ['Behind the scenes with our reptile keepers', PostStatus::Published],
                ['Why we never sell rescued animals', PostStatus::Published],
                ['Our new education room is open', PostStatus::Published],
                ['Meet the team: the weekend feeding rota', PostStatus::Published],
                ['Annual report on exotic species welfare', PostStatus::Published],
                ['Spring clean: what we recycle and why', PostStatus::Published],
                ['Thank you to our local business supporters', PostStatus::Published],
                ['Planning the new tropical house', PostStatus::Published],
                ['School visits are back this term', PostStatus::Published],
                ['A guide to our volunteer roles', PostStatus::Published],
                ['Updating our animal welfare policy', PostStatus::Draft],
                ['Autumn appeal letter', PostStatus::InReview],
            ],
            Channel::ApesShelterRescue->value => [
                ['Pickle the bearded dragon finds a new home', PostStatus::Published],
                ['Rescued tortoises settle into their new enclosure', PostStatus::Published],
                ['How we rehome surrendered parrots', PostStatus::Published],
                ['Three corn snakes arrive after a house move', PostStatus::Published],
                ['Meet Biscuit, our shyest leopard gecko', PostStatus::Published],
                ['Adopting a reptile: what to expect', PostStatus::Published],
                ['Foster carers needed for small mammals', PostStatus::Published],
                ['A busy week at the rescue centre', PostStatus::Published],
                ['Open adoption morning in January', PostStatus::Scheduled],
                ['Urgent appeal for heat mats', PostStatus::Unpublished],
            ],
            Channel::ApesPetCareClinic->value => [
                ['Winter care guide for bearded dragons', PostStatus::Published],
                ['Clinic opening hours over the holidays', PostStatus::Published],
                ['Spotting shell rot early', PostStatus::Published],
                ['Why your gecko needs calcium', PostStatus::Published],
                ['Booking a health check for a new pet', PostStatus::Published],
                ['Parasite checks: what happens at the clinic', PostStatus::Published],
                ['Choosing the right vivarium lighting', PostStatus::Draft],
                ['Nail trims for tortoises', PostStatus::InReview],
            ],
            Channel::ApesTimes->value => [
                ['Reader letters: your rescue stories', PostStatus::Published],
                ['Exotic pets and the law: a plain-English guide', PostStatus::Published],
                ['Five myths about keeping chameleons', PostStatus::Published],
                ['Ten years on the volunteer rota', PostStatus::Published],
                ['Photo diary: a day at the sanctuary', PostStatus::Published],
                ['The puzzle page returns next month', PostStatus::Draft],
            ],
            Channel::MyapesPortal->value => [
                ['Your MyAPES account now shows donation history', PostStatus::Published],
                ['Updating your contact preferences', PostStatus::Published],
                ['Two-step sign-in is now available', PostStatus::Published],
                ['Coming soon: volunteer shift booking', PostStatus::Scheduled],
            ],
            Channel::Stream4Apes->value => [
                ['Our 24-hour charity stream raised £1,240', PostStatus::Published],
                ['Stream schedule for November', PostStatus::Published],
                ['How to join a fundraising stream', PostStatus::Published],
                ['Behind the scenes of the stream set-up', PostStatus::Draft],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function paragraphs(): array
    {
        return [
            'Our keepers start each morning by checking temperatures, humidity and water bowls in every enclosure before the first feed of the day.',
            'Most of the animals in our care were surrendered by owners who could no longer look after them, often after a house move or a change in family circumstances.',
            'Every new arrival spends at least two weeks in quarantine, where our clinic team checks for parasites, shell or skin problems and signs of poor diet.',
            'Volunteers help with cleaning, enrichment and record keeping, and many of them have been with us for years. We could not do this work without them.',
            'Reptiles need very specific heat and light, so a reliable thermostat and the right UVB lamp make more difference to their health than almost anything else.',
            'If you are thinking about adopting, our team will talk you through the set-up, the costs and the long-term commitment before you meet any of the animals.',
            'Donations from readers paid for new heat mats, a replacement water filter and a month of fresh greens for the tortoise house.',
            'We keep detailed notes on weight, feeding and shedding for every animal, which helps us spot problems early and share the right advice with new owners.',
            'School groups can visit on weekday mornings during term time. Sessions last about an hour and include a talk, a short tour and time for questions.',
            'Please never release an unwanted exotic pet into the wild. Contact a rescue instead: we would much rather help than see an animal suffer.',
            'The clinic is open for booked appointments on weekdays, and the team can advise on diet, enclosures and when a pet needs to see a vet.',
            'Thank you to everyone who shared our appeal. Every share helps us reach people who might be able to foster, adopt or volunteer.',
        ];
    }

    /**
     * Invented animals used for media alt text and captions.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function animals(): array
    {
        return [
            ['Pickle', 'bearded dragon'],
            ['Biscuit', 'leopard gecko'],
            ['Marmalade', 'corn snake'],
            ['Pebble', 'Hermann\'s tortoise'],
            ['Kiwi', 'green-cheeked conure'],
            ['Sprout', 'crested gecko'],
            ['Fudge', 'African pygmy hedgehog'],
            ['Juniper', 'royal python'],
            ['Waffle', 'blue-tongued skink'],
            ['Nimbus', 'cockatiel'],
            ['Clementine', 'veiled chameleon'],
            ['Bramble', 'chinchilla'],
            ['Rolo', 'red-eared terrapin'],
            ['Saffron', 'king snake'],
            ['Truffle', 'degu'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function tags(): array
    {
        return [
            ['Reptiles', 'reptiles'],
            ['Birds', 'birds'],
            ['Small mammals', 'small-mammals'],
            ['Adoption', 'adoption'],
            ['Volunteering', 'volunteering'],
            ['Fundraising', 'fundraising'],
            ['Clinic advice', 'clinic-advice'],
            ['Events', 'events'],
            ['Education', 'education'],
            ['Annual report', 'annual-report'],
        ];
    }

    /**
     * @return list<array{0: string, 1: PostStatus}>
     */
    public static function pages(): array
    {
        return [
            ['About APES CIC', PostStatus::Published],
            ['Volunteer with us', PostStatus::Published],
            ['Our adoption process', PostStatus::Published],
            ['Visiting the sanctuary', PostStatus::Published],
            ['Accessibility statement', PostStatus::Published],
            ['Clinic price list', PostStatus::Draft],
        ];
    }

    /**
     * @return list<string>
     */
    public static function comments(): array
    {
        return [
            'Lovely to see them doing so well.',
            'Thank you for all the work you do.',
            'We adopted a gecko from you last year and she is thriving.',
            'Is the open day suitable for young children?',
            'Great advice, I had no idea about the UVB lamps.',
            'Shared with my local pet group.',
            'What a gorgeous photo!',
            'How can I sign up to volunteer at weekends?',
            'Brilliant news, well done everyone.',
            'Could you write more about caring for older tortoises?',
        ];
    }

    /**
     * Fictional street names for demo addresses.
     *
     * @return list<string>
     */
    public static function streets(): array
    {
        return [
            'Gecko Grove', 'Tortoise Terrace', 'Lizard Lane', 'Parrot Place', 'Iguana Avenue',
            'Chameleon Close', 'Terrapin Way', 'Python Parade', 'Skink Street', 'Conure Crescent',
        ];
    }
}
